<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateYawareReport;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Report::with('employee')->latest('report_date');

        if (! $request->user()->isAdmin()) {
            $query->whereRelation('employee', 'user_id', $request->user()->id);
        }

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
        $this->authorizeAccess($request, $report);

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

        $report->update([
            'status' => Report::STATUS_PENDING,
            'error_message' => null,
        ]);

        GenerateYawareReport::dispatch($report);

        return response()->json(['data' => $report->load('employee')], 201);
    }

    public function download(Request $request, Report $report): BinaryFileResponse
    {
        $this->authorizeAccess($request, $report);

        $file = $report->files()->latest('id')->first();
        abort_unless($file, 404, 'Файл звіту ще не згенеровано.');

        $absolutePath = storage_path('app/'.$file->path);
        abort_unless(is_file($absolutePath), 404, 'Файл звіту не знайдено на диску.');

        return response()->download($absolutePath, $file->original_name);
    }

    private function authorizeAccess(Request $request, Report $report): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless($report->employee?->user_id === $request->user()->id, 403);
    }
}
