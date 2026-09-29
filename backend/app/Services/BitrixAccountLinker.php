<?php

namespace App\Services;

use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Прив'язує акаунт порталу до працівника після авторизації: обмінює код на
 * токени і питає сам Бітрікс, кому вони видані. Тож чужий акаунт (і чужі
 * таски) працівник вибрати не може.
 */
class BitrixAccountLinker
{
    /**
     * @throws BitrixLinkFailed з поясненням для людини
     */
    public function link(int $userId, string $code): BitrixAccount
    {
        $workspace = BitrixWorkspace::active();
        $oauth = BitrixOAuth::forWorkspace($workspace);

        if (! $oauth) {
            throw new BitrixLinkFailed('Портал Бітрікс24 більше не підключено.');
        }

        try {
            $tokens = $oauth->exchangeCode($code, BitrixOAuth::redirectUri());
        } catch (RequestException|RuntimeException $exception) {
            Log::warning("Бітрікс24 не видав токен: {$exception->getMessage()}");

            throw new BitrixLinkFailed('Бітрікс24 не видав токен — перевірте реквізити застосунку.');
        }

        // Застосунок міг бути встановлений і на іншому порталі: приймаємо
        // авторизацію лише з того, який підключила команда.
        if ($tokens['portal_host'] && mb_strtolower($tokens['portal_host']) !== mb_strtolower($workspace->portalHost())) {
            throw new BitrixLinkFailed('Це інший портал Бітрікса — авторизуйтесь на '.$workspace->portalHost().'.');
        }

        $profile = $this->profile($tokens);

        // Один акаунт порталу — один наш користувач: інакше двоє отримували б
        // однакові таски, і незрозуміло, чий це насправді робочий день.
        $takenByOther = BitrixAccount::query()
            ->where('bitrix_user_id', $profile['id'])
            ->where('user_id', '!=', $userId)
            ->exists();

        if ($takenByOther) {
            throw new BitrixLinkFailed('Цей акаунт Бітрікса вже прив’язано до іншого користувача сервісу.');
        }

        return BitrixAccount::updateOrCreate(['user_id' => $userId], [
            'bitrix_user_id' => $profile['id'],
            'bitrix_user_name' => $profile['name'],
            'bitrix_email' => $profile['email'],
            'member_id' => $tokens['member_id'],
            'client_endpoint' => $tokens['client_endpoint'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);
    }

    /**
     * Хто власник токенів. Пошта й ім'я — з картки користувача: profile
     * часто віддає їх порожніми, а картку не кожен портал дозволяє читати.
     *
     * @return array{id: string, name: string, email: ?string}
     */
    private function profile(array $tokens): array
    {
        try {
            $profile = BitrixService::profileWithToken($tokens['client_endpoint'], $tokens['access_token']);
        } catch (RequestException|RuntimeException $exception) {
            Log::warning("Бітрікс24 не віддав профіль: {$exception->getMessage()}");

            throw new BitrixLinkFailed('Не вдалося отримати ваш профіль на порталі — спробуйте ще раз.');
        }

        if (! $profile['id']) {
            throw new BitrixLinkFailed('Бітрікс24 не повернув ваш акаунт на порталі.');
        }

        $card = ['name' => null, 'email' => null];

        try {
            $card = BitrixService::userCardWithToken($tokens['client_endpoint'], $tokens['access_token'], $profile['id']);
        } catch (RequestException|RuntimeException $exception) {
            Log::info("Бітрікс24 не віддав картку користувача: {$exception->getMessage()}");
        }

        return [
            'id' => $profile['id'],
            'name' => $card['name'] ?: ($profile['name'] ?: $card['email'] ?: "Акаунт #{$profile['id']}"),
            'email' => $card['email'] ?: $profile['email'],
        ];
    }
}
