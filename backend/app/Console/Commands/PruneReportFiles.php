<?php

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Прибирає з диска старі теки звітів (xlsx, json воркера, скріншоти помилок).
 * Дані дня вже лежать в історичних таблицях і Google Таблиці, а файл за
 * пів року тому ніхто не завантажує — без чистки диск VPS тільки росте.
 * Рядки reports і report_files лишаються: звіт видно, недоступне лише
 * завантаження.
 */
class PruneReportFiles extends Command
{
    protected $signature = 'reports:prune-files
        {--days= : Скільки днів тримати файли після останньої генерації звіту}
        {--dry-run : Лише показати, що буде видалено}';

    protected $description = 'Видаляє з диска файли давніх звітів';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('yaware.report_files_retention_days'));

        if ($days < 1) {
            $this->error('Кількість днів має бути додатною.');

            return self::FAILURE;
        }

        $root = storage_path('app/reports');

        if (! File::isDirectory($root)) {
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $directories = collect(File::directories($root))
            ->filter(fn (string $directory) => ctype_digit(basename($directory)));

        // Звіт перегенерували вчора за давню дату — файл свіжий, тож дивимось
        // на час останньої зміни звіту, а не на report_date.
        $reports = Report::whereIn('id', $directories->map(fn (string $directory) => (int) basename($directory)))
            ->pluck('updated_at', 'id');

        $pruned = 0;

        foreach ($directories as $directory) {
            $updatedAt = $reports->get((int) basename($directory));

            // Теку без звіту в базі вже ніхто не відкриє.
            if ($updatedAt !== null && $updatedAt->gte($cutoff)) {
                continue;
            }

            if (! $this->option('dry-run')) {
                File::deleteDirectory($directory);
            }

            $pruned++;
        }

        $verb = $this->option('dry-run') ? 'Буде видалено' : 'Видалено';
        $this->info("{$verb} тек звітів: {$pruned} (старші за {$days} дн.).");

        return self::SUCCESS;
    }
}
