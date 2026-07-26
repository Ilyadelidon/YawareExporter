<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Throwable;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';

    protected $description = 'Реєструє вебхук Telegram-бота на APP_URL/api/telegram/webhook (разово після налаштування бота чи зміни домену)';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->isConfigured() || ! config('services.telegram.webhook_secret')) {
            $this->error('Задайте TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME і TELEGRAM_WEBHOOK_SECRET у .env.');

            return self::FAILURE;
        }

        try {
            $result = $telegram->registerWebhook();
        } catch (Throwable $exception) {
            $this->error("Не вдалося зареєструвати вебхук: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Вебхук зареєстровано: '.json_encode($result, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
