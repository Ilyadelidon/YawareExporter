<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeMemory;
use App\Services\Ai\EmployeeMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Пам'ять AI по працівнику. Увесь контролер під middleware('admin') —
 * саме керівник виправляє вердикти, які модель поставила неправильно.
 */
class EmployeeMemoryController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        $rows = EmployeeMemory::where('employee_id', $employee->id)
            ->orderByDesc('occurrences')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $rows,
            // Скільки днів вердикт AI живе до переперевірки — фронт це пояснює.
            'recheck_days' => EmployeeMemoryService::RECHECK_DAYS,
        ]);
    }

    /**
     * Ручний рядок: найчастіше — факт про робочий контекст, який модель сама
     * не виведе (графік, внутрішній сервіс, специфіка посади).
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([EmployeeMemory::KIND_ACTIVITY, EmployeeMemory::KIND_FACT])],
            'name' => ['required', 'string', 'max:255'],
            'verdict' => ['nullable', Rule::in(EmployeeMemory::VERDICTS), Rule::requiredIf(
                fn () => $request->input('kind') === EmployeeMemory::KIND_ACTIVITY
            )],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $memory = EmployeeMemory::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'kind' => $validated['kind'],
                'name' => $validated['name'],
            ],
            [
                'verdict' => $validated['verdict'] ?? null,
                'note' => $validated['note'] ?? null,
                'source' => EmployeeMemory::SOURCE_ADMIN,
                'checked_at' => now(),
            ],
        );

        return response()->json(['data' => $memory], 201);
    }

    public function update(Request $request, Employee $employee, EmployeeMemory $memory): JsonResponse
    {
        $this->assertBelongsTo($employee, $memory);

        $validated = $request->validate([
            'verdict' => ['nullable', Rule::in(EmployeeMemory::VERDICTS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Будь-яка правка робить рядок адмінським: далі модель його не чіпає
        // і він не протухає за RECHECK_DAYS.
        $memory->update($validated + [
            'source' => EmployeeMemory::SOURCE_ADMIN,
            'checked_at' => now(),
        ]);

        return response()->json(['data' => $memory]);
    }

    public function destroy(Employee $employee, EmployeeMemory $memory): JsonResponse
    {
        $this->assertBelongsTo($employee, $memory);

        $memory->delete();

        return response()->json(null, 204);
    }

    private function assertBelongsTo(Employee $employee, EmployeeMemory $memory): void
    {
        abort_unless($memory->employee_id === $employee->id, 404);
    }
}
