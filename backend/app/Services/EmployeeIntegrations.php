<?php

namespace App\Services;

use App\Models\BitrixWorkspace;
use App\Models\Employee;
use App\Models\User;

/**
 * Що з інтеграцій працівник підключив сам — лише з бази, без походів у
 * зовнішні API, щоб список працівників відкривався миттєво.
 */
class EmployeeIntegrations
{
    /** Особистий токен Бітрікса без командного порталу нічого не дає. */
    private function __construct(private readonly bool $bitrixPortal) {}

    public static function make(): self
    {
        return new self(BitrixWorkspace::active() !== null);
    }

    /**
     * Хто ще жодного разу не входив, той нічого й не міг підключити: тоді
     * `has_account` = false.
     *
     * @return array<string, mixed>
     */
    public function present(Employee $employee): array
    {
        $user = $employee->user;

        return $user ? ['has_account' => true, ...$this->connected($user)] : ['has_account' => false];
    }

    private function connected(User $user): array
    {
        $bitrix = $user->bitrixAccount;
        $spreadsheet = $user->hasSpreadsheet();

        return [
            'task_provider' => $user->taskProvider(),
            'trello' => [
                'connected' => $user->hasTrelloConnected(),
                'username' => $user->trello_member_username,
                'board_url' => $user->trello_board_id ? TrelloService::boardUrl($user->trello_board_id) : null,
            ],
            'bitrix' => [
                'connected' => $bitrix !== null && $this->bitrixPortal,
                'username' => $bitrix?->bitrix_user_name,
            ],
            'google' => [
                'connected' => $spreadsheet,
                'url' => $spreadsheet ? GoogleSheetsService::spreadsheetUrl($user->google_spreadsheet_id) : null,
            ],
            'telegram' => [
                'connected' => $user->hasTelegramConnected(),
            ],
        ];
    }
}
