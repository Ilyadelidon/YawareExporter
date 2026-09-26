<?php

namespace App\Console\Commands;

use App\Services\PlanBitrixSync;
use Illuminate\Console\Command;

/**
 * Прогін синхронізації «Планів» з Бітрікс24 (раз на 5 хвилин за розкладом):
 * задачі з тегом «План» з'являються й оновлюються в плані, а зміни сервісу,
 * які не вдалося надіслати одразу, дотискаються.
 */
class SyncPlansWithBitrix extends Command
{
    protected $signature = 'plans:sync-bitrix';

    protected $description = 'Синхронізує задачі планів із задачами Бітрікс24 з тегом «План»';

    public function handle(PlanBitrixSync $sync): int
    {
        // Бітрікс не підключено — не аварія, просто нема з чим синхронізувати.
        if (! PlanBitrixSync::isAvailable()) {
            $this->info('Бітрікс24 не підключено — синхронізувати нічого.');

            return self::SUCCESS;
        }

        $summary = $sync->sync();

        $this->info(sprintf(
            'Бітрікс24 → плани: нових %d, оновлених %d, розвʼязаних %d; у Бітрікс надіслано %d.',
            $summary['created'], $summary['updated'], $summary['unlinked'], $summary['pushed'],
        ));

        foreach ($summary['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
