<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Технічний Telegram адміністратора: підсумок ранкового прогону і тривоги
 * монітора. Чат окремий від особистих сповіщень про звіти, і кожен
 * адміністратор підключає свій сам.
 */
class OpsTelegramController extends Controller
{
    private const LINK_CODE_TTL_MINUTES = 15;

    public function status(Request $request, TelegramService $telegram): JsonResponse
    {
        return response()->json([
            'configured' => $telegram->isConfigured(),
            'connected' => (bool) $request->user()->ops_telegram_chat_id,
        ]);
    }

    /**
     * Той самий deep-link, що й для особистих сповіщень, але код у кеші
     * позначений як технічний — вебхук запише чат в інше поле.
     */
    public function link(Request $request, TelegramService $telegram): JsonResponse
    {
        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот не налаштований на сервері (TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME).');

        $code = Str::random(40);
        Cache::put(
            "telegram-link:{$code}",
            ['user_id' => $request->user()->id, 'target' => 'ops'],
            now()->addMinutes(self::LINK_CODE_TTL_MINUTES),
        );

        return response()->json(['url' => $telegram->linkUrl($code)]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $request->user()->forceFill(['ops_telegram_chat_id' => null])->save();

        return response()->json(['message' => 'Технічні сповіщення в Telegram вимкнено.']);
    }

    /**
     * Пробне повідомлення: переконатися, що тривога справді дійде, до того як
     * вона знадобиться.
     */
    public function test(Request $request, TelegramService $telegram): JsonResponse
    {
        $chatId = $request->user()->ops_telegram_chat_id;

        abort_unless($chatId, 422, 'Спершу підключіть Telegram для технічних сповіщень.');
        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот не налаштований на сервері.');

        try {
            $telegram->sendMessage($chatId, '🧪 Перевірка: технічні сповіщення TeamReporter доходять у цей чат.');
        } catch (Throwable $exception) {
            // Найчастіше — бота заблоковано або чат видалено.
            abort(502, 'Telegram не прийняв повідомлення. Можливо, бота заблоковано — підключіть чат наново.');
        }

        return response()->json(['message' => 'Пробне повідомлення надіслано — перевірте Telegram.']);
    }
}
