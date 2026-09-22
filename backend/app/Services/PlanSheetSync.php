<?php

namespace App\Services;

use App\Models\PlanProject;
use Illuminate\Support\Facades\Log;

/**
 * Один прогін обміну зі спільною Google Таблицею: для кожного неархівного
 * проекту спершу підтягнути з аркуша те, що там наробили руками, і лише
 * потім перезаписати аркуш. Порядок саме такий — запис повний, тож усе, що
 * не встигли прочитати, було б затерто.
 *
 * Зворотне злиття зараз вимкнене (`plans.sheet_pull`), тож прогін зводиться
 * до вивантаження: аркуш — дзеркало сервісу.
 *
 * Архівні проекти не чіпаються взагалі: їхні аркуші лишаються такими, якими
 * були на момент архівації.
 */
class PlanSheetSync
{
    public function __construct(
        private readonly GoogleSheetsService $sheets,
        private readonly PlanSheetExporter $exporter,
        private readonly PlanSheetImporter $importer,
    ) {}

    /**
     * @return array{titles: list<string>, lines: list<string>} назви аркушів і що сталося людською мовою
     */
    public function run(string $spreadsheetId): array
    {
        $titles = [];
        $lines = [];

        foreach (PlanProject::whereNull('archived_at')->orderBy('name')->get() as $project) {
            $title = PlanSheetExporter::sheetTitle($project);

            foreach ($this->pull($spreadsheetId, $project, $title) as $line) {
                Log::info("Плани: {$line}");
                $lines[] = $line;
            }

            // Сітку будуємо після злиття — в аркуш має піти вже оновлений план.
            [$rows, $widths, $snapshot] = $this->exporter->grid($project);

            $this->exporter->write($spreadsheetId, $project, $rows, $widths);
            $project->update(['sheet_snapshot' => $snapshot, 'sheet_synced_at' => now()]);

            $titles[] = $title;
        }

        return ['titles' => $titles, 'lines' => $lines];
    }

    /**
     * @return list<string>
     */
    private function pull(string $spreadsheetId, PlanProject $project, string $title): array
    {
        // Зворотне злиття вимкнене (config/plans.php): аркуш лишається
        // дзеркалом сервісу, а не другим місцем, де ведуть план.
        if (! config('plans.sheet_pull')) {
            return [];
        }

        $snapshot = $project->sheet_snapshot;

        // Проект, якого ще не вивантажували: звіряти аркуш немає з чим, і
        // все, що в ньому є, ми ж зараз і запишемо.
        if (! is_array($snapshot) || ($snapshot['dates'] ?? []) === []) {
            return [];
        }

        $cells = $this->sheets->readSheet($spreadsheetId, $title);

        if ($cells === null) {
            return ["«{$title}»: аркуша в таблиці немає — вивантаження створить його заново"];
        }

        $report = $this->importer->pull($project, $cells, $snapshot);

        $applied = array_filter([
            $report['created'] > 0 ? "нових рядків: {$report['created']}" : null,
            $report['updated'] > 0 ? "правок у задачах і розділах: {$report['updated']}" : null,
            $report['days'] > 0 ? "відміток днів: {$report['days']}" : null,
        ]);

        return [
            ...($applied === [] ? [] : ["«{$project->name}»: з таблиці підтягнуто — ".implode(', ', $applied)]),
            ...array_map(fn (string $warning) => "«{$project->name}»: {$warning}", $report['warnings']),
        ];
    }
}
