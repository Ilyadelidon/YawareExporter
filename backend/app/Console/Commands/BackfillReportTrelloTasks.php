<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\TrelloService;
use Illuminate\Console\Command;
use Throwable;

class BackfillReportTrelloTasks extends Command
{
    protected $signature = 'trello:backfill-report-tasks';

    protected $description = 'Разово заповнює знімок Trello-тасок для старих завершених звітів з legacy-дошки (TRELLO_BOARD_ID у .env)';

    public function handle(): int
    {
        // Старі звіти генерувалися саме з legacy-дошки через глобальні креди,
        // тому знімок відновлюємо з неї, а не з поточної дошки користувача.
        // Глобальний fallback з конфіга знято, тому env читається напряму:
        // разова команда, змінні задаються тільки на час її запуску.
        $trello = new TrelloService(
            env('TRELLO_API_TOKEN'),
            env('TRELLO_BOARD_ID'),
        );

        if (! $trello->isConfigured()) {
            $this->error('Legacy-креди Trello відсутні: задайте env-змінні TRELLO_API_TOKEN і TRELLO_BOARD_ID на час запуску команди.');

            return self::FAILURE;
        }

        $reports = Report::where('status', Report::STATUS_COMPLETED)
            ->whereNull('tasks')
            ->orderBy('report_date')
            ->get();

        if ($reports->isEmpty()) {
            $this->info('Звітів без знімка немає — нічого заповнювати.');

            return self::SUCCESS;
        }

        foreach ($reports as $report) {
            $date = $report->report_date->format('Y-m-d');

            try {
                $tasks = $trello->tasksForDate($date);
            } catch (Throwable $exception) {
                $this->warn("Звіт #{$report->id} ({$date}): пропущено — {$exception->getMessage()}");

                continue;
            }

            $report->update(['tasks' => $tasks]);
            $this->line("Звіт #{$report->id} ({$date}): збережено ".count($tasks).' тасок.');
        }

        return self::SUCCESS;
    }
}
