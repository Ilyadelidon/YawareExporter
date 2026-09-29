<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Report::with('employee')
            ->withCount('files')
            ->visibleTo($request->user())
            ->latest('report_date');

        if ($request->filled('employee_id') && $request->user()->isAdmin()) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        // Порівняння по самій колонці, а не whereDate: обгортка в DATE()/strftime()
        // вимикає індекс (employee_id, report_date). Carbon зводимо до Y-m-d —
        // інакше в SQLite '2026-07-01' порівнюється з '2026-07-01 00:00:00'
        // лексикографічно і межа діапазону губиться.
        if ($request->filled('date_from')) {
            $query->where('report_date', '>=', $request->date('date_from')?->toDateString());
        }

        if ($request->filled('date_to')) {
            $query->where('report_date', '<=', $request->date('date_to')?->toDateString());
        }

        return response()->json($query->paginate(20));
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        abort_unless($report->isVisibleTo($request->user()), 403);

        return response()->json([
            'data' => $report->load(['employee', 'files']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $validated = $request->validate([
                'employee_id' => ['required', 'exists:employees,id'],
                'report_date' => ['required', 'date_format:Y-m-d'],
            ]);
            $employeeId = $validated['employee_id'];
        } else {
            $validated = $request->validate([
                'report_date' => ['required', 'date_format:Y-m-d'],
            ]);
            $employeeId = $user->employee?->id;
            abort_unless($employeeId, 403, 'До вашого акаунта не привʼязано працівника Yaware.');
        }

        $report = Report::firstOrCreate(
            [
                'employee_id' => $employeeId,
                'report_date' => $validated['report_date'],
            ],
            ['status' => Report::STATUS_PENDING],
        );

        if ($report->status === Report::STATUS_PROCESSING) {
            return response()->json([
                'message' => 'Звіт уже генерується.',
                'data' => $report->load('employee'),
            ], 409);
        }

        $report->queueGeneration();

        return response()->json(['data' => $report->load('employee')], 201);
    }

    public function download(Request $request, Report $report): BinaryFileResponse
    {
        abort_unless($report->isVisibleTo($request->user()), 403);
        abort_unless($report->files()->exists(), 404, 'Файл звіту ще не згенеровано.');

        $file = $report->excelFile();
        abort_unless(
            $file,
            404,
            'Файлу звіту вже немає на диску: файли старші за '.config('yaware.report_files_retention_days').' дн. видаляються. Перегенеруйте звіт, щоб отримати файл.',
        );

        return response()->download($file->absolutePath(), $file->original_name);
    }
}
