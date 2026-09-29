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

        $user = $request->user();
        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        $stats = DailyStat::with('employee')
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

        return response()->json([
            'data' => $stats,
            'totals' => DailyStat::totals($stats),
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
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
