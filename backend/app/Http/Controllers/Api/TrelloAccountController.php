<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrelloService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrelloAccountController extends Controller
{
    /**
     * Стан підключення + api_key, з якого SPA будує authorize-URL.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $board = [];

        if ($user->hasTrelloConnected() && $user->trello_board_id) {
            try {
                $board = TrelloService::forUser($user)->board($user->trello_board_id);
            } catch (RequestException) {
                // Дошку могли видалити або токен відкликали — статус все одно віддаємо.
            }
        }

        return response()->json([
            'connected' => $user->hasTrelloConnected(),
            'username' => $user->trello_member_username,
            'board_id' => $user->trello_board_id,
            'board_name' => $board['name'] ?? null,
            'board_url' => $board['shortUrl'] ?? null,
            'api_key' => config('services.trello.key'),
        ]);
    }

    /**
     * Зберігає токен, який SPA зчитала з fragment після trello.com/1/authorize.
     * Виклик members/me — водночас валідація токена і перевірка, що він виданий під наш API key.
     */
    public function storeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        try {
            $member = TrelloService::withToken($validated['token'])->member();
        } catch (RequestException) {
            return response()->json([
                'message' => 'Trello відхилив токен. Спробуйте підключитися ще раз.',
            ], 422);
        }

        $request->user()->forceFill([
            'trello_token' => $validated['token'],
            'trello_member_username' => $member['username'] ?? null,
        ])->save();

        return response()->json([
            'message' => 'Trello підключено.',
            'username' => $member['username'] ?? null,
        ]);
    }

    /**
     * Відключає інтеграцію: відкликає токен у Trello і чистить поля користувача.
     */
    public function destroyToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTrelloConnected()) {
            try {
                TrelloService::forUser($user)->revokeToken();
            } catch (RequestException $exception) {
                // Токен уже міг бути відкликаний на боці Trello — локально все одно чистимо.
                Log::warning("Не вдалося відкликати Trello-токен користувача #{$user->id}: {$exception->getMessage()}");
            }
        }

        $user->forceFill([
            'trello_token' => null,
            'trello_member_username' => null,
            'trello_board_id' => null,
        ])->save();

        return response()->json(['message' => 'Trello відключено.']);
    }

    /**
     * Відкриті дошки користувача — для вибору існуючої замість створення нової.
     */
    public function boards(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTrelloConnected()) {
            return response()->json(['message' => 'Спершу підключіть Trello.'], 409);
        }

        try {
            $boards = TrelloService::forUser($user)->boards();
        } catch (RequestException $exception) {
            return $this->trelloErrorResponse($exception);
        }

        return response()->json(['data' => $boards]);
    }

    /**
     * Створює нову дошку (з шаблону, якщо задано TRELLO_TEMPLATE_BOARD_ID) і робить її активною.
     */
    public function createBoard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTrelloConnected()) {
            return response()->json(['message' => 'Спершу підключіть Trello.'], 409);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $board = TrelloService::forUser($user)->createBoard($validated['name']);
        } catch (RequestException $exception) {
            return $this->trelloErrorResponse($exception);
        }

        $user->forceFill(['trello_board_id' => $board['id']])->save();

        return response()->json([
            'message' => 'Дошку створено.',
            'board' => [
                'id' => $board['id'],
                'name' => $board['name'] ?? $validated['name'],
                'url' => $board['shortUrl'] ?? $board['url'] ?? null,
            ],
        ], 201);
    }

    /**
     * Робить активною одну з існуючих дощок користувача.
     */
    public function selectBoard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTrelloConnected()) {
            return response()->json(['message' => 'Спершу підключіть Trello.'], 409);
        }

        $validated = $request->validate([
            'board_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            // Дошка має належати користувачу — звіряємо зі списком його дощок.
            $boards = collect(TrelloService::forUser($user)->boards());
        } catch (RequestException $exception) {
            return $this->trelloErrorResponse($exception);
        }

        $board = $boards->firstWhere('id', $validated['board_id']);

        if (! $board) {
            return response()->json(['message' => 'Цю дошку не знайдено серед ваших дощок Trello.'], 422);
        }

        $user->forceFill(['trello_board_id' => $board['id']])->save();

        return response()->json([
            'message' => 'Дошку вибрано.',
            'board' => ['id' => $board['id'], 'name' => $board['name'] ?? null],
        ]);
    }

    private function trelloErrorResponse(RequestException $exception): JsonResponse
    {
        $status = $exception->response->status();

        return response()->json([
            'message' => in_array($status, [401, 403], true)
                ? 'Trello відхилив токен — підключіться заново на сторінці інтеграцій.'
                : "Не вдалося звернутися до Trello (HTTP {$status}).",
        ], 502);
    }
}
