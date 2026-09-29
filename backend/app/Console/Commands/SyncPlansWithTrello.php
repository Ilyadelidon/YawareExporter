<?php

namespace App\Console\Commands;

use App\Services\Plans\PlanTrelloSync;
use Illuminate\Console\Command;

/**
 * Прогін синхронізації «Планів» з Trello (раз на 5 хвилин за розкладом):
 * картки з міткою «План» з'являються й оновлюються в плані, а зміни сервісу,
 * які не вдалося надіслати одразу, дотискаються.
 */
class SyncPlansWithTrello extends Command
{
    protected $signature = 'plans:sync-trello';

    protected $description = 'Синхронізує задачі планів із картками Trello з міткою «План»';

    public function handle(PlanTrelloSync $sync): int
    {
        if (! PlanTrelloSync::isAvailable()) {
            $this->info('Trello ніхто не підключив — синхронізувати нічого.');

            return self::SUCCESS;
        }

        $summary = $sync->sync();

        $this->info(sprintf(
            'Trello → плани: нових %d, оновлених %d, розвʼязаних %d; у Trello надіслано %d.',
            $summary['created'], $summary['updated'], $summary['unlinked'], $summary['pushed'],
        ));

        foreach ($summary['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
