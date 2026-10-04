<?php

namespace App\Services\Stats;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Таски зі звітів, на які за період пішло найбільше часу. Час таски в дні —
 * її вікно у знімку звіту (від start до due), саме так його ділить і файл звіту.
 */
class ReportTaskTime
{
    private const TOP = 8;

    /**
     * @param  Collection<int, Report>  $reports  готові звіти періоду з employee
     * @return list<array{name: string, url: ?string, seconds: int, days: int, employees: list<string>}>
     */
    public function top(Collection $reports): array
    {
        return $reports
            ->flatMap(fn (Report $report) => collect($report->tasks ?? [])->map(fn (array $task) => [
                // Картка трекера впізнається за посиланням (назву могли змінити), без нього — за назвою.
                'key' => ($task['url'] ?? null) ?: trim((string) ($task['name'] ?? '')),
                'name' => trim((string) ($task['name'] ?? '')),
                'url' => $task['url'] ?? null,
                'seconds' => $this->seconds($task),
                'date' => $report->report_date->toDateString(),
                'employee' => $report->employee?->name,
            ]))
            ->filter(fn (array $task) => $task['name'] !== '' && $task['seconds'] > 0)
            ->groupBy('key')
            ->map(fn (Collection $entries) => [
                // Остання назва картки — актуальніша.
                'name' => $entries->sortBy('date')->last()['name'],
                'url' => $entries->first()['url'],
                'seconds' => (int) $entries->sum('seconds'),
                'days' => $entries->pluck('date')->unique()->count(),
                'employees' => $entries->pluck('employee')->filter()->unique()->sort()->values()->all(),
            ])
            ->sortByDesc('seconds')
            ->take(self::TOP)
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $task */
    private function seconds(array $task): int
    {
        if (empty($task['start']) || empty($task['due'])) {
            return 0;
        }

        try {
            $start = CarbonImmutable::parse($task['start']);
            $due = CarbonImmutable::parse($task['due']);
        } catch (\Throwable) {
            return 0;
        }

        return max(0, (int) $start->diffInSeconds($due, false));
    }
}
