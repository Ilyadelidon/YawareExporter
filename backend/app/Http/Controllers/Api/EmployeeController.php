<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeIntegrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $integrations = EmployeeIntegrations::make();

        return response()->json([
            'data' => Employee::with('user.bitrixAccount')->orderBy('name')->get()
                ->map(fn (Employee $employee) => [
                    ...$employee->makeHidden('user')->toArray(),
                    'integrations' => $integrations->present($employee),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('employees')],
            ...$this->optionalRules(),
        ]);

        return response()->json(['data' => Employee::create($validated)], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->normalizeEmail($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('employees')->ignore($employee)],
            'active' => ['sometimes', 'boolean'],
            ...$this->optionalRules(),
        ]);

        $employee->update($validated);

        return response()->json(['data' => $employee]);
    }

    /**
     * Звільнення: відкликає токени, стирає креди Yaware і зачиняє двері доти,
     * доки працівника не поновлять. Історію не чіпає.
     */
    public function dismiss(Employee $employee): JsonResponse
    {
        $employee->dismiss();

        return response()->json(['data' => $employee->fresh()]);
    }

    /**
     * Поновлення звільненого. Доступ повертається не одразу: щоб зайти,
     * людина має знову підтвердити себе в Yaware — як і будь-хто інший.
     */
    public function reinstate(Employee $employee): JsonResponse
    {
        $employee->reinstate();

        return response()->json(['data' => $employee->fresh()]);
    }

    private function optionalRules(): array
    {
        return [
            'position' => ['nullable', 'string', 'max:255'],
            'yaware_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Пошта — ключ, за яким працівника знаходить вхід через Yaware, тож
     * зберігаємо її в нижньому регістрі: інакше «Ivan@» і «ivan@» стали б
     * двома людьми.
     */
    private function normalizeEmail(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }
    }
}
