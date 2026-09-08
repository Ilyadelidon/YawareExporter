<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixWorkspace;
use App\Models\User;
use App\Services\BitrixService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Бітрікс24 підключається інакше, ніж Trello: робоча область одна на команду.
 * Портал (вхідний вебхук) підключає адміністратор, а кожен працівник лише
 * обирає, який акаунт на цьому порталі його.
 */
class BitrixAccountController extends Controller
{
    /**
     * Вхідний вебхук порталу: https://portal.bitrix24.ua/rest/<id>/<код>/
     * Тільки https — це повноцінний ключ доступу до REST порталу.
     */
    private const WEBHOOK_PATTERN = '#^https://[\w.\-]+\.[a-z]{2,}/rest/\d+/[\w]+/?$#i';

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace = BitrixWorkspace::active();

        return response()->json([
            'workspace_connected' => $workspace !== null,
            'portal_url' => $workspace?->portal_url,
            'owner_name' => $workspace?->owner_name,
            'connected_by' => $workspace?->connectedBy?->name,
            'user_id' => $user->bitrix_user_id,
            'user_name' => $user->bitrix_user_name,
            'connected' => $user->hasBitrixConnected(),
            // Портал підключає лише адміністратор — решті показуємо підказку.
            'can_manage' => $user->isAdmin(),
        ]);
    }

    /**
     * Підключає портал команди. Вебхук перевіряємо викликом profile — заодно
     * дізнаємось, від чийого імені підуть запити.
     */
    public function storeWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'webhook' => ['required', 'string', 'max:500', 'regex:'.self::WEBHOOK_PATTERN],
        ], [
            'webhook.regex' => 'Очікується посилання вхідного вебхука вигляду https://ваш-портал.bitrix24.ua/rest/1/код/',
        ]);

        $webhook = rtrim(trim($validated['webhook']), '/').'/';

        try {
            $profile = BitrixService::withWebhook($webhook)->profile();
        } catch (RequestException|RuntimeException $exception) {
            return $this->bitrixErrorResponse($exception, 'Бітрікс24 відхилив вебхук — перевірте, що він активний і має права на завдання.');
        }

        $workspace = BitrixWorkspace::connect([
            'portal_url' => BitrixService::portalUrlFromWebhook($webhook),
            'webhook_url' => $webhook,
            'owner_name' => $profile['name'],
            'connected_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Портал Бітрікс24 підключено.',
            'portal_url' => $workspace->portal_url,
            'owner_name' => $workspace->owner_name,
        ], 201);
    }

    /**
     * Відключає портал команди. Вибрані акаунти працівників стають безпідставними,
     * тож чистимо їх разом із робочою областю.
     */
    public function destroyWorkspace(): JsonResponse
    {
        BitrixWorkspace::query()->delete();

        User::query()
            ->whereNotNull('bitrix_user_id')
            ->update(['bitrix_user_id' => null, 'bitrix_user_name' => null]);

        return response()->json(['message' => 'Портал Бітрікс24 відключено.']);
    }

    /**
     * Активні користувачі порталу — з них працівник обирає свій акаунт.
     */
    public function users(): JsonResponse
    {
        $bitrix = BitrixService::forWorkspace();

        if (! $bitrix->hasWorkspace()) {
            return response()->json(['message' => 'Портал Бітрікс24 ще не підключено.'], 409);
        }

        try {
            $users = $bitrix->users();
        } catch (RequestException|RuntimeException $exception) {
            return $this->bitrixErrorResponse($exception);
        }

        return response()->json(['data' => $users]);
    }

    /**
     * Прив'язує працівника до його акаунта на порталі — саме за ним
     * фільтруються таски (RESPONSIBLE_ID).
     */
    public function selectUser(Request $request): JsonResponse
    {
        $bitrix = BitrixService::forWorkspace();

        if (! $bitrix->hasWorkspace()) {
            return response()->json(['message' => 'Портал Бітрікс24 ще не підключено.'], 409);
        }

        $validated = $request->validate([
            'bitrix_user_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $portalUsers = collect($bitrix->users());
        } catch (RequestException|RuntimeException $exception) {
            return $this->bitrixErrorResponse($exception);
        }

        $portalUser = $portalUsers->firstWhere('id', $validated['bitrix_user_id']);

        if (! $portalUser) {
            return response()->json(['message' => 'Такого користувача немає серед активних на порталі.'], 422);
        }

        $request->user()->forceFill([
            'bitrix_user_id' => $portalUser['id'],
            'bitrix_user_name' => $portalUser['name'],
        ])->save();

        return response()->json([
            'message' => 'Акаунт Бітрікса вибрано.',
            'user' => $portalUser,
        ]);
    }

    /**
     * Знімає прив'язку працівника до акаунта — сам портал команди лишається.
     */
    public function destroyUser(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'bitrix_user_id' => null,
            'bitrix_user_name' => null,
        ])->save();

        return response()->json(['message' => 'Акаунт Бітрікса відв\'язано.']);
    }

    private function bitrixErrorResponse(Throwable $exception, ?string $fallback = null): JsonResponse
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return response()->json([
                'message' => $fallback ?? (in_array($status, [401, 403, 404], true)
                    ? 'Бітрікс24 відхилив вебхук — портал треба підключити заново.'
                    : "Не вдалося звернутися до Бітрікс24 (HTTP {$status})."),
            ], 502);
        }

        Log::warning("Бітрікс24 повернув помилку: {$exception->getMessage()}");

        return response()->json([
            'message' => $fallback ?? "Бітрікс24 повернув помилку: {$exception->getMessage()}",
        ], 502);
    }
}
