<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpsTelegramChat;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Технічний Telegram адміністратора: підсумок ранкового прогону і тривоги
 * монітора. Чати окремі від особистих сповіщень про звіти; кожен
 * адміністратор підключає свої сам і може тримати кілька — особистий,
 * спільну групу підтримки, черговий канал.
 */
class OpsTelegramController extends Controller
{
    private const LINK_CODE_TTL_MINUTES = 15;

    public function status(Request $request, TelegramService $telegram): JsonResponse
    {
        $chats = $request->user()->opsTelegramChats()->get();

        // Чати, підключені до того, як ми почали запамʼятовувати назву,
        // підписані лише номером — питаємо імʼя в Telegram один раз.
        $chats->whereNull('title')->each(fn (OpsTelegramChat $chat) => $chat->syncTitle($telegram));

        return response()->json([
            'configured' => $telegram->isConfigured(),
            'max_chats' => OpsTelegramChat::MAX_PER_USER,
            'chats' => $chats->map(fn (OpsTelegramChat $chat) => [
                'id' => $chat->id,
                'title' => $chat->label(),
                'kind' => $chat->isGroup() ? 'group' : 'private',
                'connected_at' => $chat->created_at,
            ])->all(),
        ]);
    }

    /**
     * Той самий deep-link, що й для особистих сповіщень, але код у кеші
     * позначений як технічний — вебхук додасть чат у список, а не в поле
     * особистих сповіщень.
     */
    public function link(Request $request, TelegramService $telegram): JsonResponse
    {
        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот не налаштований на сервері (TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME).');

        // Ліміт перевіряємо ще до видачі посилання: краще сказати про нього тут,
        // ніж відмовити вже в боті після Start.
        abort_if(
            $request->user()->opsTelegramChats()->count() >= OpsTelegramChat::MAX_PER_USER,
            422,
            'Підключено максимум чатів ('.OpsTelegramChat::MAX_PER_USER.'). Приберіть зайвий, щоб додати новий.',
        );

        $code = Str::random(40);
        Cache::put(
            "telegram-link:{$code}",
            ['user_id' => $request->user()->id, 'target' => 'ops'],
            now()->addMinutes(self::LINK_CODE_TTL_MINUTES),
        );

        return response()->json(['url' => $telegram->linkUrl($code)]);
    }

    public function unlink(Request $request, OpsTelegramChat $chat): JsonResponse
    {
        $chat = $this->ownChat($request, $chat);
        $chat->delete();

        return response()->json([
            'message' => "Чат «{$chat->label()}» більше не отримує технічних сповіщень.",
        ]);
    }

    /**
     * Пробне повідомлення в конкретний чат: переконатися, що тривога справді
     * дійде, до того як вона знадобиться.
     */
    public function test(Request $request, OpsTelegramChat $chat, TelegramService $telegram): JsonResponse
    {
        $chat = $this->ownChat($request, $chat);

        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот не налаштований на сервері.');

        try {
            $telegram->sendMessage($chat->chat_id, '🧪 Перевірка: технічні сповіщення TeamReporter доходять у цей чат.');
        } catch (Throwable $exception) {
            // Найчастіше — бота заблоковано або чат видалено.
            abort(502, 'Telegram не прийняв повідомлення. Можливо, бота заблоковано або вилучено з групи — підключіть чат наново.');
        }

        return response()->json(['message' => "Пробне повідомлення надіслано в «{$chat->label()}»."]);
    }

    /**
     * Чужий чат — не просто «не знайдено»: свої чати адміністратор веде сам,
     * і сповіщення іншого він ні прибрати, ні перевірити не може.
     */
    private function ownChat(Request $request, OpsTelegramChat $chat): OpsTelegramChat
    {
        abort_unless($chat->user_id === $request->user()->id, 404);

        return $chat;
    }
}
