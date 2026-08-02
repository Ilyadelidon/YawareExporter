<?php

namespace App\Console\Commands;

use App\Services\HeartbeatService;
use App\Services\OpsMonitor;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Півгодинна перевірка стану: якщо щось лягло — надсилає сповіщення, а не
 * лишає це помітним лише за відсутністю ранкового підсумку.
 */
class OpsHealthcheck extends Command
{
    protected $signature = 'ops:healthcheck
        {--force : Надіслати сповіщення, навіть якщо про ці ж проблеми вже повідомляли}';

    protected $description = 'Перевіряє стан сервісу і сповіщає розробника про проблеми';

    public function handle(OpsMonitor $monitor, TelegramService $telegram, HeartbeatService $heartbeat): int
    {
        $problems = $monitor->problems();

        if ($problems === []) {
            $this->info('Проблем не виявлено.');
            $heartbeat->ok();

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        // Зовнішній сервіс дізнається про проблему одразу, навіть якщо
        // повторне сповіщення в Telegram зараз притримується.
        $heartbeat->fail();

        if (! $this->option('force') && ! $monitor->shouldAlert($problems)) {
            $this->line('Про ці ж проблеми вже повідомляли — повтор притримано.');

            return self::SUCCESS;
        }

        $telegram->notifyOps(implode("\n", ['⚠️ Схоже, щось зламалось:', ...array_map(
            fn (string $problem) => "• {$problem}",
            $problems,
        )]));

        $monitor->markAlerted($problems);
        $this->info('Сповіщення надіслано.');

        return self::SUCCESS;
    }
}
