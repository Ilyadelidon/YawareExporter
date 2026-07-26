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
