<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixWorkspace;
use App\Models\Employee;
use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\TrelloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $employees = Employee::with('user.bitrixAccount')->orderBy('name')->get();
        $bitrixPortal = BitrixWorkspace::active() !== null;

        return response()->json([
            'data' => $employees->map(fn (Employee $employee) => [
                ...$employee->makeHidden('user')->toArray(),
                'integrations' => $this->integrations($employee->user, $bitrixPortal),
            ]),
        ]);
    }

    /**
     * Що з інтеграцій працівник підключив сам — лише з бази, без походів у
     * зовнішні API, щоб список відкривався миттєво. Хто ще жодного разу не
     * входив, той нічого й не міг підключити: тоді `has_account` = false.
     */
    private function integrations(?User $user, bool $bitrixPortal): array
    {
        if (! $user) {
            return ['has_account' => false];
        }

        $bitrix = $user->bitrixAccount;

        return [
            'has_account' => true,
            'task_provider' => $user->taskProvider(),
            'trello' => [
                'connected' => $user->hasTrelloConnected(),
                'username' => $user->trello_member_username,
                'board_url' => $user->trello_board_id ? TrelloService::boardUrl($user->trello_board_id) : null,
            ],
            'bitrix' => [
                // Особистий токен без командного порталу нічого не дає.
                'connected' => $bitrix !== null && $bitrixPortal,
                'username' => $bitrix?->bitrix_user_name,
            ],
            'google' => [
                'connected' => $user->hasSpreadsheet(),
                'url' => $user->hasSpreadsheet()
                    ? GoogleSheetsService::spreadsheetUrl($user->google_spreadsheet_id)
                    : null,
            ],
            'telegram' => [
                'connected' => $user->hasTelegramConnected(),
            ],
        ];
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
