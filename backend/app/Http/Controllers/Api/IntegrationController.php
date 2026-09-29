<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixWorkspace;
use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\Tasks\TaskProviders;
use App\Services\TrelloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Зведення по персональних інтеграціях користувача — для інтерфейсу поза
 * сторінкою інтеграцій (подробиці й підключення живуть там).
 */
class IntegrationController extends Controller
{
    /**
     * Чи готовий працівник до формування звіту: без активного трекера й
     * Google Таблиці генерація заблокована. Лише з бази, без походів у
     * зовнішні API, — сторінка звітів не має чекати на Trello чи Google.
     */
    public function status(Request $request, GoogleSheetsService $sheets): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'tracker' => [
                'provider' => $user->taskProvider(),
                'connected' => $user->hasTaskTrackerConnected(),
            ],
            'sheets' => [
                'connected' => $sheets->hasGoogleAccount() && $user->hasSpreadsheet(),
            ],
        ]);
    }

    /**
     * Прямі посилання на підключені сервіси — для кнопок переходу в боковому
     * меню. Що не підключено, те повертається як null, і кнопки просто немає.
     */
    public function links(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'google' => $this->googleLink($user),
            'tracker' => $this->trackerLink($user),
        ]);
    }

    private function googleLink(User $user): ?array
    {
        if (! $user->hasSpreadsheet()) {
            return null;
        }

        return [
            'url' => GoogleSheetsService::spreadsheetUrl($user->google_spreadsheet_id),
        ];
    }

    /**
     * Лише активний трекер: перемкнувши Trello на Бітрікс, працівник має
     * бачити кнопку того, куди зараз пишуться таски.
     */
    private function trackerLink(User $user): ?array
    {
        return $user->taskProvider() === User::TASK_PROVIDER_BITRIX
            ? $this->bitrixLink($user)
            : $this->trelloLink($user);
    }

    private function trelloLink(User $user): ?array
    {
        if (! $user->hasTrelloConnected() || ! $user->trello_board_id) {
            return null;
        }

        // Без відповіді Trello кнопка лишається з коротким посиланням,
        // решту з'ясує сторінка інтеграцій.
        $board = TrelloService::activeBoard($user);

        return [
            'provider' => User::TASK_PROVIDER_TRELLO,
            'label' => TaskProviders::label(User::TASK_PROVIDER_TRELLO),
            'name' => $board['name'] ?? null,
            'url' => $board['url'] ?? TrelloService::boardUrl($user->trello_board_id),
        ];
    }

    private function bitrixLink(User $user): ?array
    {
        $workspace = BitrixWorkspace::active();
        $account = $user->bitrixAccount;

        if ($workspace === null || $account === null) {
            return null;
        }

        return [
            'provider' => User::TASK_PROVIDER_BITRIX,
            'label' => TaskProviders::label(User::TASK_PROVIDER_BITRIX),
            'name' => $account->bitrix_user_name ?: $workspace->portalHost(),
            'url' => $workspace->userTasksUrl($account->bitrix_user_id),
        ];
    }
}
