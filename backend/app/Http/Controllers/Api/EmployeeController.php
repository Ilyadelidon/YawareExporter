<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Employee::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:employees,email'],
            'yaware_id' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = Employee::create($validated);

        return response()->json(['data' => $employee], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        if ($request->has('email')) {
            $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:employees,email,'.$employee->id],
            'yaware_id' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
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
}
