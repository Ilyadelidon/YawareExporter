<?php

namespace App\Services\Stats;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Таски за період зі знімків готових звітів: скільки їх було, скільки часу на них
 * пішло, у якій колонці трекера кожна зараз (за останнім знімком) і на які пішло
 * найбільше часу. Час таски в дні — її вікно у знімку (від start до due), саме так
 * його ділить і файл звіту.
 */
class PeriodTasks
{
    private const TOP = 8;

    /**
     * @param  Collection<int, Report>  $reports  готові звіти періоду з employee
     * @return array{total: int, seconds: int, lists: list<array{list: string, count: int}>, top: list<array<string, mixed>>}
     */
    public function summarize(Collection $reports): array
    {
        $tasks = $reports
            ->flatMap(fn (Report $report) => collect($report->tasks ?? [])->map(fn (array $task) => [
                // Картка трекера впізнається за посиланням (назву могли змінити), без нього — за назвою.
                'key' => ($task['url'] ?? null) ?: trim((string) ($task['name'] ?? '')),
                'name' => trim((string) ($task['name'] ?? '')),
                'url' => $task['url'] ?? null,
                'list' => trim((string) ($task['list'] ?? '')),
                'seconds' => $this->seconds($task),
                'date' => $report->report_date->toDateString(),
                'employee' => $report->employee?->name,
            ]))
            ->filter(fn (array $task) => $task['name'] !== '')
            ->groupBy('key')
            ->map(function (Collection $entries) {
                // Останній знімок картки — актуальніші назва й колонка.
                $latest = $entries->sortBy('date')->last();

                return [
                    'name' => $latest['name'],
                    'url' => $latest['url'],
                    'list' => $latest['list'],
                    'seconds' => (int) $entries->sum('seconds'),
                    'days' => $entries->pluck('date')->unique()->count(),
                    'employees' => $entries->pluck('employee')->filter()->unique()->sort()->values()->all(),
                ];
            });

        return [
            'total' => $tasks->count(),
            'seconds' => (int) $tasks->sum('seconds'),
            'lists' => $tasks
                ->filter(fn (array $task) => $task['list'] !== '')
                ->countBy('list')
                ->sortDesc()
                ->map(fn (int $count, string $list) => ['list' => $list, 'count' => $count])
                ->values()
                ->all(),
            'top' => $tasks
                ->filter(fn (array $task) => $task['seconds'] > 0)
                ->sortByDesc('seconds')
                ->take(self::TOP)
                ->values()
                ->all(),
        ];
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
