<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramController extends Controller
{
    private const LINK_CODE_TTL_MINUTES = 15;

    public function status(Request $request, TelegramService $telegram): JsonResponse
    {
        return response()->json([
            'configured' => $telegram->isConfigured(),
            'connected' => $request->user()->hasTelegramConnected(),
        ]);
    }

    /**
     * Видає одноразовий deep-link на бота. Код живе 15 хв у кеші;
     * бот обміняє його на chat_id у вебхуку.
     */
    public function link(Request $request, TelegramService $telegram): JsonResponse
    {
        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот ще не налаштований адміністратором.');

        $code = Str::random(40);
        Cache::put("telegram-link:{$code}", $request->user()->id, now()->addMinutes(self::LINK_CODE_TTL_MINUTES));

        return response()->json(['url' => $telegram->linkUrl($code)]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $request->user()->forceFill(['telegram_chat_id' => null])->save();

        return response()->json(['message' => 'Telegram відключено — сповіщення більше не надсилаються.']);
    }

    /**
     * Вебхук Telegram. Єдина команда — /start <код привʼязки>. Відповідь
     * завжди 200: помилка обробки не має змушувати Telegram ретраїти апдейт.
     */
    public function webhook(Request $request, TelegramService $telegram): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_unless(
            $secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403,
        );

        try {
            $this->handleUpdate($request->input('message', []), $telegram);
        } catch (Throwable $exception) {
            Log::warning("Помилка обробки Telegram-вебхука: {$exception->getMessage()}");
        }

        return response()->json(['ok' => true]);
    }

    private function handleUpdate(array $message, TelegramService $telegram): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! $chatId || ! str_starts_with($text, '/start')) {
            return;
        }

        $code = trim(substr($text, strlen('/start')));
        $userId = $code !== '' ? Cache::pull("telegram-link:{$code}") : null;
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            $telegram->sendMessage(
                (string) $chatId,
                'Це бот сповіщень TeamReporter. Щоб підключити його, натисніть «Підключити» у блоці інтеграцій на '.config('app.url').' — посилання дійсне 15 хвилин.',
            );

            return;
        }

        $user->forceFill(['telegram_chat_id' => (string) $chatId])->save();

        $telegram->sendMessage(
            (string) $chatId,
            "✅ Telegram підключено до акаунта «{$user->name}». Сюди приходитимуть сповіщення про згенеровані звіти і незаповнені таски.",
        );
    }
}
