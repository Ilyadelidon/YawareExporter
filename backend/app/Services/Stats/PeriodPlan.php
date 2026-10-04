<?php

namespace App\Services\Stats;

use App\Models\PlanTask;
use Illuminate\Support\Collection;

/**
 * План за період: у якому стані задачі плану зараз і над якими з них працювали
 * в періоді — за відмітками днів у Планах.
 */
class PeriodPlan
{
    private const TOP = 8;

    /**
     * @param  Collection<int, PlanTask>  $planTasks  задачі плану з project, employee і days лише за період
     * @return array<string, mixed>
     */
    public function summarize(Collection $planTasks): array
    {
        // Стан плану — лише по живих проектах: архівні вже не план.
        $active = $planTasks->filter(fn (PlanTask $task) => $task->project && ! $task->project->archived_at);
        $counts = $active->countBy('status');

        $worked = $planTasks
            ->filter(fn (PlanTask $task) => $task->days->isNotEmpty())
            ->map(fn (PlanTask $task) => [
                'title' => $task->title,
                'project' => $task->project?->name,
                'employee' => $task->employee?->name,
                'status' => $task->status,
                'status_label' => PlanTask::STATUS_LABELS[$task->status] ?? $task->status,
                'days' => $task->days->count(),
            ]);

        return [
            'total' => $active->count(),
            'statuses' => collect(PlanTask::STATUS_LABELS)
                ->map(fn (string $label, string $status) => ['status' => $status, 'label' => $label, 'count' => $counts[$status] ?? 0])
                ->filter(fn (array $row) => $row['count'] > 0)
                ->values()
                ->all(),
            'worked_tasks' => $worked->count(),
            'worked_days' => (int) $worked->sum('days'),
            'top' => $worked->sortByDesc('days')->take(self::TOP)->values()->all(),
        ];
    }
}
