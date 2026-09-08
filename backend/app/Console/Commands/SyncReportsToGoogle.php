<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportFile;
use App\Services\ReportSheetPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Доливає в Google Таблицю дні, які не потрапили туди через помилку на боці
 * Google. Звіт уже згенеровано — Excel лежить на диску, тож повторювати
 * повний прогін воркера (кілька хвилин у браузері) немає потреби.
 */
class SyncReportsToGoogle extends Command
{
    protected $signature = 'reports:sync-google
        {report?* : ID звітів; без аргументів — усі свіжі звіти з помилкою вивантаження}
        {--days=7 : За скільки останніх днів шукати такі звіти}';

    protected $description = 'Повторно вивантажує готові звіти в Google Таблицю';

    public function handle(ReportSheetPublisher $publisher): int
    {
        $reports = $this->reports();

        if ($reports->isEmpty()) {
            $this->info('Звітів для вивантаження не знайдено.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($reports as $report) {
            $label = "Звіт #{$report->id} за {$report->report_date->format('d.m.Y')}";
            $excelPath = $this->excelPath($report);

            if (! $excelPath) {
                $this->error("{$label}: Excel-файл звіту не знайдено — потрібна повна перегенерація.");
                $failed++;

                continue;
            }

            [$url, $warnings] = $publisher->publish($report, $excelPath);

            foreach ($warnings as $warning) {
                $this->warn("{$label}: {$warning}");
            }

            if (! $url) {
                $failed++;

                continue;
            }

            $this->applyResult($report, $url, $warnings);
            $this->info("{$label}: вивантажено — {$url}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, Report>
     */
    private function reports(): Collection
    {
        $ids = array_filter((array) $this->argument('report'));

        if ($ids !== []) {
            return Report::with('employee.user')->whereIn('id', $ids)->get();
        }

        $since = CarbonImmutable::now()->subDays(max(1, (int) $this->option('days')));

        return Report::with('employee.user')
            ->where('status', Report::STATUS_COMPLETED)
            ->where('updated_at', '>=', $since)
            ->get()
            ->filter(fn (Report $report) => ReportSheetPublisher::uploadFailed($report))
            ->values();
    }

    private function excelPath(Report $report): ?string
    {
        $file = $report->files()
            ->where('type', 'combined_excel')
            ->latest('id')
            ->first();

        $path = $file instanceof ReportFile ? storage_path('app/'.$file->path) : null;

        return $path && is_file($path) ? $path : null;
    }

    /**
     * Прибирає з підсумку звіту попередження про невдале вивантаження і
     * ставить посилання на вкладку — щоб у картці звіту не лишалось згадки
     * про помилку, якої вже немає.
     *
     * @param  array<int, string>  $warnings
     */
    private function applyResult(Report $report, string $url, array $warnings): void
    {
        $summary = $report->summary ?? [];

        $kept = collect(explode("\n", $this->withoutFailureWarning((string) ($summary['Попередження'] ?? ''))))
            ->merge($warnings)
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->unique()
            ->all();

        $summary['Google Таблиця'] = $url;

        if ($kept === []) {
            unset($summary['Попередження']);
        } else {
            $summary['Попередження'] = implode("\n", $kept);
        }

        $report->update(['summary' => $summary]);
    }

    /**
     * Відрізає попередження про невдале вивантаження з підсумку. Саме
     * «відрізає», а не «прибирає рядок»: текст помилки Google буває
     * багаторядковим (json тіла відповіді), а попередження про Google
     * додаються останніми — тож усе від маркера й до кінця стосується його.
     */
    private function withoutFailureWarning(string $warnings): string
    {
        $marker = mb_strpos($warnings, ReportSheetPublisher::FAILED_MARKER);

        if ($marker === false) {
            return $warnings;
        }

        $lineStart = mb_strrpos(mb_substr($warnings, 0, $marker), "\n");

        return $lineStart === false ? '' : mb_substr($warnings, 0, $lineStart);
    }
}
