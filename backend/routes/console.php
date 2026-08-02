<?php

use App\Services\TelegramService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Щоранку в будні — звіти за попередній будній день (у понеділок за пʼятницю).
// Потрібен системний cron із `php artisan schedule:run` щохвилини (див. DEPLOY.md).
Schedule::command('reports:generate-daily')
    ->weekdays()
    ->at('07:00')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'))
    // Команда сама шле підсумок прогону; сюди потрапляємо, лише якщо вона
    // впала до кінця (виняток, ненульовий код виходу) — тоді підсумку не
    // буде взагалі, і без цього сигналу ранок минув би тихо.
    ->onFailure(function () {
        app(TelegramService::class)->notifyOps(
            "❌ Планувальник: reports:generate-daily завершилась помилкою.\n"
            .'Деталі — у storage/logs/scheduler.log і laravel.log на сервері.'
        );
    });

// Активна перевірка стану: сама шукає ознаки аварії й сповіщає, замість того
// щоб покладатись на здогад «підсумку не прийшло — мабуть, щось не так».
Schedule::command('ops:healthcheck')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
