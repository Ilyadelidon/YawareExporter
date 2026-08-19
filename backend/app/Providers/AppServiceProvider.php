<?php

namespace App\Providers;

use App\Queue\RetryingDatabaseConnector;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Черга живе в тому ж SQLite-файлі, що й дані, тож о 07:00 воркери
        // регулярно натикались на зайняту базу — і штатна черга списувала
        // такі джоби у failed_jobs. Див. RetryingDatabaseQueue.
        Queue::extend('database', fn () => new RetryingDatabaseConnector($this->app['db']));
    }
}
