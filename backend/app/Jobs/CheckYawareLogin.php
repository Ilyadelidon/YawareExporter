<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\User;
use App\Services\YawareAuthService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Асинхронна перевірка кредів через Playwright-логін у Yaware (до ~2 хв),
 * винесена з HTTP-запиту, щоб не блокувати API на час браузерної сесії.
 *
 * Результат кладеться в кеш під check_id (шифрованим — там токен і статус);
 * фронтенд полить GET /api/auth/login/pending/{id} і забирає токен один раз.
 * Окрема черга logins — щоб логін не стояв за довгими джобами звітів.
 */
class CheckYawareLogin implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public const QUEUE = 'logins';

    // Playwright-процес усередині обмежений 120 с — плюс запас на його старт.
    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(
        public string $checkId,
        public string $email,
        public string $password,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public static function cacheKey(string $checkId): string
    {
        return "auth.login_check.{$checkId}";
    }

    /**
     * Зберігає стан перевірки (pending/ok/failed) шифрованим у кеші.
     */
    public static function storeResult(string $checkId, array $result): void
    {
        Cache::put(self::cacheKey($checkId), Crypt::encrypt($result), now()->addMinutes(15));
    }

    /**
     * @return array|null null — check_id невідомий або протермінований.
     */
    public static function readResult(string $checkId): ?array
    {
        $encrypted = Cache::get(self::cacheKey($checkId));

        return $encrypted === null ? null : Crypt::decrypt($encrypted);
    }

    public function handle(YawareAuthService $yawareAuth): void
    {
        $result = $yawareAuth->attemptLogin($this->email, $this->password);

        if ($result === null) {
            self::storeResult($this->checkId, [
                'status' => 'failed',
                'message' => 'Не вдалося увійти в Yaware з цими даними.',
            ]);

            return;
        }

        $employeeName = $result['employee']['name'] ?? '';

        $user = User::updateOrCreate(
            ['email' => $this->email],
            [
                'name' => $employeeName ?: $this->email,
                'password' => $this->password,
            ],
        );

        Employee::updateOrCreate(
            ['email' => $this->email],
            [
                'user_id' => $user->id,
                'name' => $employeeName ?: $this->email,
                'yaware_id' => $result['employee']['id'] ?? null,
                'yaware_password' => $this->password,
                'active' => true,
            ],
        );

        $user = $user->fresh();

        self::storeResult($this->checkId, [
            'status' => 'ok',
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $user->apiPayload(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        self::storeResult($this->checkId, [
            'status' => 'failed',
            'message' => 'Перевірка Yaware не виконалась — спробуйте увійти ще раз.',
        ]);
    }
}
