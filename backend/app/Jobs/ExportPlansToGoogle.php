<?php

namespace App\Jobs;

use App\Services\GoogleSheetsService;
use App\Services\PlanSheetSync;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вивантаження планів у спільну Google Таблицю — у черзі, а не в запиті:
 * велику таблицю, яку давно не відкривали, Google «прокидає» хвилинами
 * (перший batchUpdate на тестовій копії плану йшов 168 с, наступний — 3 с).
 * Стан останнього прогону лежить у кеші, сторінка планів його опитує.
 *
 * Якщо увімкнути `plans.sheet_pull`, прогін спершу підтягне з аркушів правки,
 * зроблені руками в таблиці, і тоді підсумок злиття піде і в кеш, і в Telegram.
 *
 * Стани розділені: «queued» — задача лише в черзі (якщо вона там висить,
 * значить не працює обробник черги), «running» — Google уже пише аркуші.
 */
class ExportPlansToGoogle implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const STATUS_KEY = 'plans.export.status';

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /**
     * @param  bool  $notifyOps  підсумок прогону в Telegram розробнику: потрібен
     *                           для нічного вивантаження, за яким ніхто не стежить
     */
    public function __construct(public string $spreadsheetId, public bool $notifyOps = false) {}

    /**
     * @return array{status: string, message: ?string, url: ?string, at: string}|null
     */
    public static function status(): ?array
    {
        return Cache::get(self::STATUS_KEY);
    }

    public static function markQueued(string $spreadsheetId): void
    {
        self::remember('queued', null, $spreadsheetId);
    }

    public function handle(PlanSheetSync $sync): void
    {
        self::remember('running', null, $this->spreadsheetId);

        $startedAt = microtime(true);

        ['titles' => $titles, 'lines' => $lines] = $sync->run($this->spreadsheetId);

        Log::info(sprintf(
            'Плани вивантажено в таблицю %s: %d аркуш(ів) за %.1f с.',
            $this->spreadsheetId,
            count($titles),
            microtime(true) - $startedAt,
        ));

        // Підсумок злиття видно на сторінці планів; порожній — значить у
        // таблиці нічого не правили, і казати нема про що.
        self::remember('done', $lines === [] ? null : implode(PHP_EOL, $lines), $this->spreadsheetId);

        if ($this->notifyOps && $lines !== []) {
            app(TelegramService::class)->notifyOps(implode(PHP_EOL, [
                '🗂 Плани ↔ Google Таблиця',
                ...array_map(fn (string $line) => "• {$line}", $lines),
            ]));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning("Не вдалося вивантажити плани в таблицю {$this->spreadsheetId}: {$exception->getMessage()}");

        self::remember('failed', mb_substr($exception->getMessage(), 0, 400), $this->spreadsheetId);
    }

    /**
     * Строк життя позначки — довший за добу навмисно: вивантаження йде щодня,
     * і при рівно добовому вона зникала б якраз перед наступним прогоном —
     * сторінка казала б, що експорту ще не було.
     */
    private static function remember(string $status, ?string $message, string $spreadsheetId): void
    {
        Cache::put(self::STATUS_KEY, [
            'status' => $status,
            'message' => $message,
            'url' => GoogleSheetsService::spreadsheetUrl($spreadsheetId),
            'at' => now()->toIso8601String(),
        ], now()->addDays(3));
    }
}
