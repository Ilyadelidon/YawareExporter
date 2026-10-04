<?php

namespace App\Services\Reports;

use App\Models\Employee;
use App\Models\Report;
use App\Services\TelegramService;
use Carbon\CarbonImmutable;

/**
 * Підсумок ранкової автогенерації розробнику в Telegram: по кожному
 * працівнику — сформувався звіт чи ні і чому. Шлеться, коли черга
 * відпрацювала всі звіти прогону, а не в момент постановки в чергу: тоді
 * результату ще не знає ніхто.
 *
 * Це водночас сигнал живості планувальника: повідомлення приходить щобудня,
 * тож його відсутність і є ознакою, що cron або воркер лягли.
 */
class DailyRunSummary
{
    public function __construct(private TelegramService $telegram) {}

    /**
     * @param  list<int>  $reportIds  звіти прогону (і поставлені, і ті, що вже були)
     * @param  array<int, string>  $skipped  id працівника => чому звіт навіть не ставився
     */
    public function send(string $date, array $reportIds, array $skipped): void
    {
        $this->telegram->notifyOps($this->text($date, $reportIds, $skipped));
    }

    /**
     * @param  list<int>  $reportIds
     * @param  array<int, string>  $skipped
     */
    public function text(string $date, array $reportIds, array $skipped): string
    {
        $done = [];
        $notDone = [];

        $reports = Report::with('employee')
            ->whereIn('id', $reportIds)
            ->get()
            ->sortBy(fn (Report $report) => $report->employee?->name);

        foreach ($reports as $report) {
            $name = $report->employee?->name ?? "звіт #{$report->id}";

            if ($reason = $this->failureReason($report)) {
                $notDone[] = $this->item($name, $reason);
            } else {
                $done[] = $this->item($name, $this->note($report));
            }
        }

        $employees = Employee::whereIn('id', array_keys($skipped))->get()->keyBy('id');

        foreach ($skipped as $employeeId => $reason) {
            $notDone[] = $this->item($employees->get($employeeId)?->name ?? "працівник #{$employeeId}", $reason);
        }

        $lines = [
            '🗓 Автогенерація звітів за '.CarbonImmutable::parse($date)->format('d.m.Y'),
            ...$this->section('✅ Сформовано', $done),
            ...$this->section('❌ Не сформовано', $notDone),
        ];

        if ($done === [] && $notDone === []) {
            $lines[] = 'Активних працівників не знайдено — перевірте список працівників.';
        }

        return implode("\n", $lines);
    }

    /**
     * Чому звіт не вважається сформованим; null — сформований. Звіт без
     * тасок чи без Google Таблиці формально готовий, але для працівника це
     * той самий несформований звіт (див. Report::isIncomplete()).
     */
    private function failureReason(Report $report): ?string
    {
        return match (true) {
            in_array($report->status, [Report::STATUS_FAILED, Report::STATUS_BLOCKED], true) => $this->errorMessage($report),
            // Черга вже закінчила, а звіт не дійшов до кінцевого статусу —
            // джоба впала винятком раніше, ніж записала причину.
            $report->status !== Report::STATUS_COMPLETED => "генерація обірвалась, звіт лишився у статусі «{$report->status}»",
            $report->hasNoTasks() && ! $this->isEmptyDay($report) => 'тасок за день немає, є лише статистика дня',
            $report->isIncomplete() => 'звіт неповний: таски не отримано або не вивантажено в Google Таблицю',
            default => null,
        };
    }

    private function note(Report $report): ?string
    {
        return $this->isEmptyDay($report) ? 'день без активності в Yaware' : null;
    }

    private function isEmptyDay(Report $report): bool
    {
        return ($report->summary[Report::SUMMARY_RESULT] ?? null) === Report::EMPTY_DAY_RESULT;
    }

    private function errorMessage(Report $report): string
    {
        $message = trim((string) $report->error_message);

        return $message === '' ? 'причину не записано' : mb_substr($message, 0, 300);
    }

    private function item(string $name, ?string $note): string
    {
        return $note ? "• {$name} — {$note}" : "• {$name}";
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function section(string $title, array $items): array
    {
        return $items === [] ? [] : ['', "{$title}: ".count($items), ...$items];
    }
}
