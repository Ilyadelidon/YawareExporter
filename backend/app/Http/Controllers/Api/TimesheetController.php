<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Timesheet;
use App\Support\Month;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    /**
     * Табель за місяць (лише адміністратор): робочий час кожного працівника по днях.
     */
    public function index(Request $request, Timesheet $timesheet): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = Month::parse($validated['month'] ?? null);

        return response()->json([
            'month' => $month->format('Y-m'),
            'days_in_month' => $month->daysInMonth,
            'data' => $timesheet->rows($month),
        ]);
    }
}
