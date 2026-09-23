<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixWorkspace;
use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\TrelloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Прямі посилання на підключені сервіси користувача — для кнопок переходу
 * в боковому меню. Тут лише готові адреси: що не підключено, те повертається
 * як null, і кнопки просто немає.
 */
class IntegrationLinkController extends Controller
{
    /** Скільки тримаємо назву й адресу дошки Trello, щоб не ходити в API на кожній сторінці. */
    private const BOARD_CACHE_TTL = 3600;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'google' => $this->googleLink($user),
            'tracker' => $this->trackerLink($user),
        ]);
    }

    private function googleLink(User $user): ?array
    {
        if (! $user->google_spreadsheet_id) {
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

        $board = $this->trelloBoard($user);

        return [
            'provider' => User::TASK_PROVIDER_TRELLO,
            'label' => 'Trello',
            'name' => $board['name'] ?? null,
            // Trello приймає в /b/ і повний id дошки, тож без відповіді API
            // посилання все одно веде куди треба.
            'url' => $board['url'] ?? "https://trello.com/b/{$user->trello_board_id}",
        ];
    }

    /**
     * @return array{name: ?string, url: ?string}|null
     */
    private function trelloBoard(User $user): ?array
    {
        return Cache::remember(
            "trello-board-link:{$user->id}:{$user->trello_board_id}",
            self::BOARD_CACHE_TTL,
            function () use ($user) {
                try {
                    $board = TrelloService::forUser($user)->board($user->trello_board_id);
                } catch (Throwable) {
                    // Дошку могли видалити чи токен відкликали — кнопка лишається
                    // з коротким посиланням, решту з'ясує сторінка інтеграцій.
                    return null;
                }

                return ['name' => $board['name'] ?? null, 'url' => $board['shortUrl'] ?? null];
            },
        );
    }

    private function bitrixLink(User $user): ?array
    {
        $workspace = BitrixWorkspace::active();
        $account = $user->bitrixAccount;

        if ($workspace === null || $account === null) {
            return null;
        }

        $portal = rtrim($workspace->portal_url, '/');

        return [
            'provider' => User::TASK_PROVIDER_BITRIX,
            'label' => 'Бітрікс24',
            'name' => $account->bitrix_user_name ?: $workspace->portalHost(),
            'url' => "{$portal}/company/personal/user/{$account->bitrix_user_id}/tasks/",
        ];
    }
}
