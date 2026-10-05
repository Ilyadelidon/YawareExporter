<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\PlanTask;
use App\Models\Report;
use App\Services\Stats\PeriodPlanTasks;
use App\Services\Stats\PeriodTasks;
use App\Services\Stats\TopActivities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $user = $request->user();
        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        // Фільтр по працівнику — лише для адміністратора: працівник і так бачить тільки себе.
        $employeeId = $user->isAdmin() ? ($validated['employee_id'] ?? null) : null;

        // Один фільтр для днів, діяльностей, звітів і задач плану: працівник — лише свої, адмін — усі або обраний.
        $scoped = fn (Builder $query): Builder => $query
            ->visibleTo($user)
            ->when($employeeId, fn (Builder $q) => $q->where('employee_id', $employeeId));

        $stats = $scoped(DailyStat::query()->betweenDates($dateFrom, $dateTo))
            ->orderBy('date')
            ->orderBy('employee_id')
            ->get();

        return response()->json([
            'data' => $stats,
            'totals' => DailyStat::totals($stats),
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'activities' => (new TopActivities)->summarize(
                $scoped(ActivityEntry::query()->whereBetween('date', [$dateFrom, $dateTo])),
            ),
            'tasks' => (new PeriodTasks)->summarize(
                $scoped(Report::query())
                    ->where('status', Report::STATUS_COMPLETED)
                    ->whereBetween('report_date', [$dateFrom, $dateTo])
                    ->with('employee:id,name')
                    ->get(['id', 'employee_id', 'report_date', 'tasks']),
            ),
            'plan_tasks' => (new PeriodPlanTasks)->summarize(
                $scoped(PlanTask::query())
                    ->whereHas('days', fn (Builder $q) => $q->whereBetween('date', [$dateFrom, $dateTo]))
                    ->with([
                        'days' => fn ($q) => $q->whereBetween('date', [$dateFrom, $dateTo]),
                        'project:id,name',
                        'employee:id,name',
                    ])
                    ->get(),
            ),
        ]);
    }

    public function activities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $employeeId = (int) $validated['employee_id'];

        abort_unless($user->isAdmin() || $user->employee?->id === $employeeId, 403);

        $entries = ActivityEntry::where('employee_id', $employeeId)
            ->where('date', $validated['date'])
            ->orderByDesc('duration_seconds')
            ->get();

        return response()->json(['data' => $entries]);
    }
}
