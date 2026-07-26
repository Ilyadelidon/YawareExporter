<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyStat;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    /**
     * Табель за місяць: матриця «працівник × дні» з фактично відпрацьованим
     * часом із daily_stats. Показуються активні працівники плюс ті, у кого
     * є дані за місяць (навіть якщо їх уже деактивували).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m', $validated['month'] ?? now()->format('Y-m'))->startOfMonth();
        $start = $month->toDateString();
        $end = $month->endOfMonth()->toDateString();

        $stats = DailyStat::whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get()
            ->groupBy('employee_id');

        $employees = Employee::orderBy('name')
            ->where('active', true)
            ->orWhereIn('id', $stats->keys())
            ->get();

        $rows = $employees->map(function (Employee $employee) use ($stats) {
            $days = ($stats[$employee->id] ?? collect())
                ->mapWithKeys(fn (DailyStat $stat) => [$stat->date->toDateString() => (int) $stat->total_seconds]);

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'days' => (object) $days->all(),
                'total_seconds' => (int) $days->sum(),
                // Нульові дні (записані до того, як порожні дні перестали
                // потрапляти в історію) не рахуються відпрацьованими.
                'days_worked' => $days->filter(fn (int $seconds) => $seconds > 0)->count(),
            ];
        });

        return response()->json([
            'month' => $month->format('Y-m'),
            'days_in_month' => $month->daysInMonth,
            'data' => $rows->values(),
        ]);
    }
}
