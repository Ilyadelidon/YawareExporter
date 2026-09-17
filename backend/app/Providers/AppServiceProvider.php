<?php

namespace App\Providers;

use App\Database\ImmediateSQLiteConnection;
use App\Queue\RetryingDatabaseConnector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Транзакції SQLite мають починатися як запис, інакше перехід
        // читання→запис усередині них падає з «database is locked» миттєво,
        // не чекаючи busy_timeout. Реєструвати треба саме тут: у boot()
        // зʼєднання вже може бути створене. Див. ImmediateSQLiteConnection.
        Connection::resolverFor(
            'sqlite',
            fn ($connection, $database, $prefix, $config) => new ImmediateSQLiteConnection($connection, $database, $prefix, $config),
        );
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

        // Кожен POST /reports — до 10 хвилин Playwright у єдиній черзі звітів,
        // тож без ліміту кілька кліків підряд затримують ранок усій команді.
        // Адміністратор перегенеровує звіти за всіх, тому йому стеля вища.
        RateLimiter::for('reports', function (Request $request) {
            $user = $request->user();

            $limit = $user->isAdmin()
                ? Limit::perMinute(30)
                : Limit::perHour(20);

            return $limit->by('reports:'.$user->id)->response(
                fn (Request $request, array $headers) => response()->json([
                    'message' => 'Забагато запитів на генерацію звітів. Зачекайте трохи й спробуйте ще раз.',
                ], 429, $headers),
            );
        });
    }
}
