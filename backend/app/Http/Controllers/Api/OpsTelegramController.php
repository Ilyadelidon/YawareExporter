<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpsTelegramChat;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Технічний Telegram адміністратора: підсумок ранкового прогону і тривоги
 * монітора. Чати окремі від особистих сповіщень про звіти; кожен
 * адміністратор підключає свої сам і може тримати кілька — особистий,
 * спільну групу підтримки, черговий канал.
 */
class OpsTelegramController extends Controller
{
    public function status(Request $request, TelegramService $telegram): JsonResponse
    {
        return response()->json($this->state($request, $telegram));
    }

    /**
     * Той самий deep-link, що й для особистих сповіщень, але код у кеші
     * позначений як технічний — вебхук додасть чат у список, а не в поле
     * особистих сповіщень.
     */
    public function link(Request $request, TelegramService $telegram): JsonResponse
    {
        $this->ensureConfigured($telegram);

        // Ліміт перевіряємо ще до видачі посилання: краще сказати про нього тут,
        // ніж відмовити вже в боті після Start.
        abort_if(
            $request->user()->opsTelegramChats()->count() >= OpsTelegramChat::MAX_PER_USER,
            422,
            'Підключено максимум чатів ('.OpsTelegramChat::MAX_PER_USER.'). Приберіть зайвий, щоб додати новий.',
        );

        return response()->json([
            'url' => $telegram->issueLink(['user_id' => $request->user()->id, 'target' => 'ops']),
        ]);
    }

    public function unlink(Request $request, OpsTelegramChat $chat, TelegramService $telegram): JsonResponse
    {
        $this->ensureOwn($request, $chat);
        $chat->delete();

        return response()->json($this->state($request, $telegram) + [
            'message' => "Чат «{$chat->label()}» більше не отримує технічних сповіщень.",
        ]);
    }

    /**
     * Пробне повідомлення в конкретний чат: переконатися, що тривога справді
     * дійде, до того як вона знадобиться.
     */
    public function test(Request $request, OpsTelegramChat $chat, TelegramService $telegram): JsonResponse
    {
        $this->ensureOwn($request, $chat);
        $this->ensureConfigured($telegram);

        try {
            $telegram->sendMessage($chat->chat_id, '🧪 Перевірка: технічні сповіщення TeamReporter доходять у цей чат.');
        } catch (Throwable) {
            // Найчастіше — бота заблоковано або чат видалено.
            abort(502, 'Telegram не прийняв повідомлення. Можливо, бота заблоковано або вилучено з групи — підключіть чат наново.');
        }

        return response()->json(['message' => "Пробне повідомлення надіслано в «{$chat->label()}»."]);
    }

    /**
     * Стан для сторінки: чати поточного адміністратора і чи працює бот.
     *
     * @return array<string, mixed>
     */
    private function state(Request $request, TelegramService $telegram): array
    {
        $chats = $request->user()->opsTelegramChats()->get();

        // Чати, підключені до того, як ми почали запамʼятовувати назву,
        // підписані лише номером — питаємо імʼя в Telegram один раз.
        $chats->whereNull('title')->each(fn (OpsTelegramChat $chat) => $chat->syncTitle($telegram));

        return [
            'configured' => $telegram->isConfigured(),
            'max_chats' => OpsTelegramChat::MAX_PER_USER,
            'chats' => $chats->map(fn (OpsTelegramChat $chat) => [
                'id' => $chat->id,
                'title' => $chat->label(),
                'kind' => $chat->isGroup() ? 'group' : 'private',
                'connected_at' => $chat->created_at,
            ])->all(),
        ];
    }

    private function ensureConfigured(TelegramService $telegram): void
    {
        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот не налаштований на сервері (TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME).');
    }

    /**
     * Чужий чат — не просто «не знайдено»: свої чати адміністратор веде сам,
     * і сповіщення іншого він ні прибрати, ні перевірити не може.
     */
    private function ensureOwn(Request $request, OpsTelegramChat $chat): void
    {
        abort_unless($chat->user_id === $request->user()->id, 404);
    }
}
