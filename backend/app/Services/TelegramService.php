<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function isConfigured(): bool
    {
        return (bool) config('services.telegram.bot_token')
            && (bool) config('services.telegram.bot_username');
    }

    public function botUsername(): ?string
    {
        return config('services.telegram.bot_username');
    }

    /**
     * Deep-link для привʼязки акаунта: користувач відкриває його, тисне Start,
     * і бот отримує /start з кодом.
     */
    public function linkUrl(string $code): string
    {
        return 'https://t.me/'.$this->botUsername().'?start='.$code;
    }

    /**
     * Пряма відправка повідомлення. Кидає виняток при помилці API —
     * для сповіщень, які не мають валити процес, є notify().
     */
    public function sendMessage(string $chatId, string $text, ?string $parseMode = null): void
    {
        Http::asForm()
            ->post(self::API_BASE.config('services.telegram.bot_token').'/sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'disable_web_page_preview' => true,
            ]))
            ->throw();
    }

    /**
     * Сповіщення користувачу, якщо в нього привʼязаний Telegram. Помилка
     * відправки не піднімається нагору — лише попередження в лог: сповіщення
     * ніколи не має валити генерацію звіту.
     */
    public function notify(?User $user, string $text, ?string $parseMode = null): void
    {
        if (! $user || ! $user->telegram_chat_id || ! $this->isConfigured()) {
            return;
        }

        try {
            $this->sendMessage($user->telegram_chat_id, $text, $parseMode);
        } catch (Throwable $exception) {
            Log::warning("Telegram-сповіщення користувачу #{$user->id} не надіслано: {$exception->getMessage()}");
        }
    }

    /**
     * Технічне сповіщення: стан ранкової автогенерації, збої планувальника.
     * Отримують адміністратори, які підключили технічний Telegram у
     * налаштуваннях, і розробник з OPS_TELEGRAM_EMAIL (його особистий чат).
     * Працівникам такі повідомлення не йдуть.
     *
     * Один чат отримує повідомлення один раз, навіть якщо він прописаний в
     * обох місцях. Збій відправки одному адресату не зупиняє решту.
     */
    public function notifyOps(string $text, ?string $parseMode = null): void
    {
        $chatIds = User::where('role', User::ROLE_ADMIN)
            ->whereNotNull('ops_telegram_chat_id')
            ->pluck('ops_telegram_chat_id')
            ->all();

        if ($developerChatId = $this->opsEmailChatId()) {
            $chatIds[] = $developerChatId;
        }

        if (! $this->isConfigured()) {
            return;
        }

        foreach (array_unique($chatIds) as $chatId) {
            try {
                $this->sendMessage((string) $chatId, $text, $parseMode);
            } catch (Throwable $exception) {
                Log::warning("Технічне Telegram-сповіщення в чат {$chatId} не надіслано: {$exception->getMessage()}");
            }
        }
    }

    /**
     * Особистий чат розробника з OPS_TELEGRAM_EMAIL.
     *
     * Мовчазна відмова тут неприпустима: якщо адресата не знайдено або в нього
     * не привʼязаний Telegram, у лог іде попередження — інакше сповіщення про
     * збої самі зникли б непоміченими.
     */
    private function opsEmailChatId(): ?string
    {
        $email = trim((string) config('services.telegram.ops_email'));

        if ($email === '') {
            return null;
        }

        // Пошта в базі зберігається так, як її віддав Yaware, тож порівнюємо
        // без урахування регістру.
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if (! $user) {
            Log::warning("OPS_TELEGRAM_EMAIL={$email}: користувача з такою поштою немає — технічне сповіщення не надіслано.");

            return null;
        }

        if (! $user->telegram_chat_id) {
            Log::warning("OPS_TELEGRAM_EMAIL={$email}: Telegram не привʼязано — технічне сповіщення не надіслано.");

            return null;
        }

        return $user->telegram_chat_id;
    }

    /**
     * Реєструє вебхук бота на APP_URL/api/telegram/webhook із секретом,
     * який Telegram потім шле в заголовку кожного запиту.
     */
    public function registerWebhook(): array
    {
        $response = Http::asForm()
            ->post(self::API_BASE.config('services.telegram.bot_token').'/setWebhook', [
                'url' => rtrim(config('app.url'), '/').'/api/telegram/webhook',
                'secret_token' => config('services.telegram.webhook_secret'),
                'allowed_updates' => json_encode(['message']),
            ])
            ->throw();

        return $response->json();
    }
}
