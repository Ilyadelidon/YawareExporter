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
     * Технічне сповіщення розробнику (OPS_TELEGRAM_EMAIL): стан ранкової
     * автогенерації, збої планувальника. Працівникам такі повідомлення не
     * йдуть — це службовий канал на одну людину.
     *
     * Мовчазна відмова тут неприпустима: якщо адресата не знайдено або в нього
     * не привʼязаний Telegram, у лог іде попередження — інакше сповіщення про
     * збої самі зникли б непоміченими.
     */
    public function notifyOps(string $text, ?string $parseMode = null): void
    {
        $email = trim((string) config('services.telegram.ops_email'));

        if ($email === '') {
            return;
        }

        // Пошта в базі зберігається так, як її віддав Yaware, тож порівнюємо
        // без урахування регістру.
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if (! $user) {
            Log::warning("OPS_TELEGRAM_EMAIL={$email}: користувача з такою поштою немає — технічне сповіщення не надіслано.");

            return;
        }

        if (! $user->telegram_chat_id) {
            Log::warning("OPS_TELEGRAM_EMAIL={$email}: Telegram не привʼязано — технічне сповіщення не надіслано.");

            return;
        }

        $this->notify($user, $text, $parseMode);
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
