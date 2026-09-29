<?php

namespace App\Services\Reports;

use App\Models\Report;
use App\Services\Tasks\TaskProviders;
use App\Services\TelegramService;

/**
 * Telegram-сповіщення працівнику про результат генерації його звіту.
 */
class ReportNotifier
{
    public function __construct(private TelegramService $telegram) {}

    /**
     * Звіт готовий; якщо тасок у трекері за день немає — просить заповнити
     * їх і перегенерувати звіт.
     */
    public function completed(Report $report, ?array $tasks, ?string $googleSheetUrl): void
    {
        $lines = ["✅ Звіт за {$this->day($report)} згенеровано."];

        if ($googleSheetUrl) {
            $lines[] = 'Вкладка у <a href="'.e($googleSheetUrl).'">Google Таблиці</a>.';
        }

        if (empty($tasks)) {
            $lines[] = "⚠️ Тасок у {$this->tracker($report)} за цей день немає. Додайте виконані таски з проставленим часом початку й завершення і перегенеруйте звіт на ".config('app.url').', щоб вони потрапили у звіт.';
        }

        $this->telegram->notify($report->employee?->user, implode("\n", $lines), 'HTML');
    }

    public function failed(Report $report): void
    {
        $reason = mb_substr((string) $report->error_message, 0, 500);

        $this->telegram->notify(
            $report->employee?->user,
            "❌ Звіт за {$this->day($report)} не згенерувався.\n{$reason}\nСпробуйте ще раз: ".config('app.url'),
        );
    }

    public function blocked(Report $report, string $reason): void
    {
        $this->telegram->notify($report->employee?->user, implode("\n", [
            "⚠️ Звіт за {$this->day($report)} не сформовано.",
            $reason,
            "Додайте у {$this->tracker($report)} таски з проставленим часом початку й завершення так, щоб вони покрили весь день, і сформуйте звіт заново: ".config('app.url'),
        ]));
    }

    private function day(Report $report): string
    {
        return $report->report_date->format('d.m.Y');
    }

    private function tracker(Report $report): string
    {
        return TaskProviders::forUser($report->employee?->user)->providerLabel();
    }
}
