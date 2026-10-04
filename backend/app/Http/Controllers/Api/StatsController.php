<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\PlanTask;
use App\Models\Report;
use App\Services\Stats\PeriodPlan;
use App\Services\Stats\ReportTaskTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $stats = DailyStat::query()
            ->betweenDates($dateFrom, $dateTo)
            ->visibleTo($user)
            // Фільтр по працівнику — лише для адміністратора: працівник і так бачить тільки себе.
            ->when(
                $user->isAdmin() && ! empty($validated['employee_id']),
                fn ($query) => $query->where('employee_id', $validated['employee_id']),
            )
            ->orderBy('date')
            ->orderBy('employee_id')
            ->get();

        // Той самий фільтр для діяльностей, задач плану і звітів: працівник — лише свої, адмін — усі або обраний.
        $ownedBy = fn (Builder $query): Builder => $query->when(
            $user->isAdmin(),
            fn ($q) => $q->when(! empty($validated['employee_id']), fn ($q) => $q->where('employee_id', $validated['employee_id'])),
            fn ($q) => $q->whereRelation('employee', 'user_id', $user->id),
        );

        return response()->json([
            'data' => $stats,
            'totals' => DailyStat::totals($stats),
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'activities' => $this->topActivities($ownedBy(ActivityEntry::query()->whereBetween('date', [$dateFrom, $dateTo]))),
            'plan' => $this->periodPlan($ownedBy, $dateFrom, $dateTo),
            'report_tasks' => (new ReportTaskTime)->top(
                $ownedBy(Report::query())
                    ->where('status', Report::STATUS_COMPLETED)
                    ->whereBetween('report_date', [$dateFrom, $dateTo])
                    ->with('employee:id,name')
                    ->get(['id', 'employee_id', 'report_date', 'tasks']),
            ),
        ]);
    }

    /**
     * Топ діяльностей за період: сумарний час по назві.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topActivities(Builder $query): Collection
    {
        return $query
            ->selectRaw('name, productivity, MAX(category) as category, SUM(duration_seconds) as seconds')
            ->groupBy('name', 'productivity')
            ->orderByDesc('seconds')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'category' => $row->category,
                'productivity' => $row->productivity,
                'seconds' => (int) $row->seconds,
            ]);
    }

    /**
     * План за період: стан задач плану і над якими з них працювали (відмітки днів у Планах).
     *
     * @param  \Closure(Builder): Builder  $ownedBy
     * @return array<string, mixed>
     */
    private function periodPlan(\Closure $ownedBy, string $from, string $to): array
    {
        $planTasks = $ownedBy(PlanTask::query())
            ->with([
                'project:id,name,archived_at',
                'days' => fn ($query) => $query->whereBetween('date', [$from, $to]),
            ])
            ->get();

        return (new PeriodPlan)->summarize($planTasks);
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
