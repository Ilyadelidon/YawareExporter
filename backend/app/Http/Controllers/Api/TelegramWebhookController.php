<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpsTelegramChat;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вебхук бота. Єдина команда — /start <код прив'язки>: код видають
 * [[TelegramController]] (особисті сповіщення) і [[OpsTelegramController]]
 * (технічні чати адміністратора).
 */
class TelegramWebhookController extends Controller
{
    /**
     * Відповідь завжди 200: помилка обробки не має змушувати Telegram
     * ретраїти апдейт.
     */
    public function __invoke(Request $request, TelegramService $telegram): JsonResponse
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

        // У групі Telegram доставляє команду разом з іменем бота:
        // «/start@TeamReporter_Bot КОД», — тож код беремо після пробілу.
        $code = trim(preg_split('/\s+/', $text, 2)[1] ?? '');
        $link = $telegram->pullLink($code);

        // Технічний чат адміністратора — окремий вид прив'язки;
        // звичайне посилання — просто id користувача.
        if (is_array($link) && ($link['target'] ?? null) === 'ops') {
            $this->linkOpsChat(
                (string) $chatId,
                User::find($link['user_id'] ?? null),
                $message['chat'] ?? [],
                $telegram,
            );

            return;
        }

        $user = is_scalar($link) ? User::find($link) : null;

        if (! $user) {
            $telegram->sendMessage(
                (string) $chatId,
                'Це бот сповіщень TeamReporter. Щоб підключити його, натисніть «Підключити» у блоці інтеграцій на '
                    .config('app.url').' — посилання дійсне '.TelegramService::LINK_TTL_MINUTES.' хвилин.',
            );

            return;
        }

        $user->linkTelegram((string) $chatId);

        $telegram->sendMessage(
            (string) $chatId,
            "✅ Telegram підключено до акаунта «{$user->name}». Сюди приходитимуть сповіщення про згенеровані звіти і незаповнені таски.",
        );
    }

    /**
     * Технічних чатів у адміністратора може бути кілька — цей просто
     * додається до списку.
     */
    private function linkOpsChat(string $chatId, ?User $user, array $chat, TelegramService $telegram): void
    {
        // Роль могли зняти, поки посилання ще жило, — службові тривоги
        // колишньому адміністратору не належать.
        if (! $user?->isAdmin()) {
            $telegram->sendMessage($chatId, 'Посилання недійсне: технічні сповіщення може підключити лише адміністратор.');

            return;
        }

        $existing = $user->opsTelegramChats()->where('chat_id', $chatId)->first();

        if ($existing) {
            // Повторний Start у вже підключеному чаті: назва групи могла
            // змінитись — оновлюємо її, щоб список лишався впізнаваним.
            $existing->update(['title' => OpsTelegramChat::titleFrom($chat) ?? $existing->title]);

            $telegram->sendMessage($chatId, '🛠 Цей чат уже отримує технічні сповіщення TeamReporter.');

            return;
        }

        // Ліміт стережемо й тут: посилання могли взяти на останній вільний
        // слот, а Start натиснути вже після того, як його зайняв інший чат.
        if ($user->opsTelegramChats()->count() >= OpsTelegramChat::MAX_PER_USER) {
            $telegram->sendMessage(
                $chatId,
                'Підключено максимум чатів ('.OpsTelegramChat::MAX_PER_USER.'). Приберіть зайвий у налаштуваннях і спробуйте ще раз.',
            );

            return;
        }

        $user->opsTelegramChats()->create([
            'chat_id' => $chatId,
            'title' => OpsTelegramChat::titleFrom($chat),
        ]);

        $telegram->sendMessage(
            $chatId,
            '🛠 Технічні сповіщення TeamReporter підключено. Сюди приходитимуть підсумок ранкової генерації звітів і тривоги про збої сервісу.',
        );
    }
}
