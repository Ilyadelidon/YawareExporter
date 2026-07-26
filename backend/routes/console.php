<?php

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
    ->appendOutputTo(storage_path('logs/scheduler.log'));
