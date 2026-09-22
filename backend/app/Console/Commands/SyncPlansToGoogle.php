<?php

namespace App\Console\Commands;

use App\Jobs\ExportPlansToGoogle;
use App\Models\AppSetting;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;

/**
 * Те саме, що й кнопка «Вивантажити в Google», тільки за розкладом: таблиця
 * має бути свіжою щоранку, а не тоді, коли хтось про неї згадав. Ручна кнопка
 * лишається — нею оновлюють таблицю серед дня.
 *
 * Зворотне злиття правок з аркушів вимкнене (`plans.sheet_pull`), тож зараз
 * прогін лише перезаписує аркуші.
 */
class SyncPlansToGoogle extends Command
{
    protected $signature = 'plans:sync-google
        {--now : Вивантажити в цьому ж процесі, не чекаючи воркера черги}';

    protected $description = 'Вивантажує плани всіх активних проектів у підключену Google Таблицю';

    public function handle(GoogleSheetsService $sheets): int
    {
        // Невивантажені плани — не аварія: таблицю могли просто не підключити.
        // Тому тут SUCCESS, інакше планувальник щодня повідомляв би про збій
        // там, де робити нічого й не було.
        if (! $sheets->hasGoogleAccount()) {
            $this->info('Google-акаунт не підключено — вивантажувати нічим.');

            return self::SUCCESS;
        }

        $spreadsheetId = AppSetting::get(AppSetting::PLANS_SPREADSHEET_ID);

        if ($spreadsheetId === null) {
            $this->info('Таблицю планів не підключено — вивантажувати нікуди.');

            return self::SUCCESS;
        }

        ExportPlansToGoogle::markQueued($spreadsheetId);

        // `--now` — для разової перевірки на сервері, де воркер міг стояти:
        // видно і час вивантаження, і текст помилки Google просто в консолі.
        if ($this->option('now')) {
            ExportPlansToGoogle::dispatchSync($spreadsheetId);
            $this->info('Плани вивантажено: '.GoogleSheetsService::spreadsheetUrl($spreadsheetId));

            return self::SUCCESS;
        }

        // Через чергу, як і ручний експорт: «холодну» таблицю Google прокидає
        // хвилинами. Падіння джоби лягає у failed_jobs, звідки про нього
        // протягом півгодини повідомить ops:healthcheck.
        // notifyOps: за нічним прогоном ніхто не стежить, а підтягнуте з
        // таблиці (і особливо пропущене) має кудись доповідатись.
        ExportPlansToGoogle::dispatch($spreadsheetId, notifyOps: true);
        $this->info('Вивантаження планів поставлено в чергу.');

        return self::SUCCESS;
    }
}
