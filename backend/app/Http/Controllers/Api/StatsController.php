<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEntry;
use App\Models\DailyStat;
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

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        // Порівняння по самій колонці, а не whereDate: обгортка в DATE()/strftime()
        // вимикає індекси (employee_id, date) і змушує читати таблицю цілком.
        $query = DailyStat::with('employee')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date')
            ->orderBy('employee_id');

        if (! $request->user()->isAdmin()) {
            $query->whereRelation('employee', 'user_id', $request->user()->id);
        } elseif (! empty($validated['employee_id'])) {
            $query->where('employee_id', $validated['employee_id']);
        }

        $stats = $query->get();

        return response()->json([
            'data' => $stats,
            'totals' => [
                'days' => $stats->count(),
                'productive_seconds' => (int) $stats->sum('productive_seconds'),
                'unproductive_seconds' => (int) $stats->sum('unproductive_seconds'),
                'neutral_seconds' => (int) $stats->sum('neutral_seconds'),
                'total_seconds' => (int) $stats->sum('total_seconds'),
                'lateness_seconds' => (int) $stats->sum('lateness_seconds'),
            ],
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);
    }

    public function activities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        if (! $request->user()->isAdmin()) {
            abort_unless($request->user()->employee?->id === (int) $validated['employee_id'], 403);
        }

        $entries = ActivityEntry::where('employee_id', $validated['employee_id'])
            ->where('date', $validated['date'])
            ->orderByDesc('duration_seconds')
            ->get();

        return response()->json(['data' => $entries]);
    }
}
