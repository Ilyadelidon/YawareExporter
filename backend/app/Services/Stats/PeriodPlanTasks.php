<?php

namespace App\Services\Stats;

use App\Models\PlanTask;
use Illuminate\Support\Collection;

/**
 * Задачі з плану за період: над скількома працювали, скільки з них зараз виконано
 * і на перевірці, і над якими найбільше — за відмітками днів у Планах (часу в
 * Планах немає, тож міра — дні).
 */
class PeriodPlanTasks
{
    private const TOP = 8;

    /**
     * @param  Collection<int, PlanTask>  $planTasks  задачі з project, employee і days лише за період
     * @return array{total: int, done: int, review: int, top: list<array<string, mixed>>}
     */
    public function summarize(Collection $planTasks): array
    {
        $worked = $planTasks
            ->filter(fn (PlanTask $task) => $task->days->isNotEmpty())
            ->map(fn (PlanTask $task) => [
                'name' => $task->title,
                'project' => $task->project?->name,
                'status_key' => $task->status,
                'status' => PlanTask::STATUS_LABELS[$task->status] ?? null,
                'days' => $task->days->count(),
                'employee' => $task->employee?->name,
            ]);

        return [
            'total' => $worked->count(),
            'done' => $worked->where('status_key', PlanTask::STATUS_DONE)->count(),
            'review' => $worked->where('status_key', PlanTask::STATUS_REVIEW)->count(),
            'top' => $worked->sortByDesc('days')->take(self::TOP)
                ->map(fn (array $task) => collect($task)->except('status_key')->all())
                ->values()->all(),
        ];
    }
}
