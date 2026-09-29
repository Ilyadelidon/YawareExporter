<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Одноразовий переїзд бази з SQLite-файлу в MySQL (див. DEPLOY.md, розділ 5а).
 *
 * Схему в MySQL будує звичайний `php artisan migrate`, а ця команда лише
 * переносить рядки — з тими самими id, щоб не зламати зовнішні ключі й
 * посилання, які вже пішли назовні (id звітів у Telegram, у Google-таблиці).
 * Цільова база має бути порожньою: доливати в живу базу команда відмовляється,
 * бо злиття двох історій — це вже не переїзд.
 */
class CopySqliteToMysql extends Command
{
    protected $signature = 'db:copy-from-sqlite
        {path : Шлях до файлу SQLite, з якого переносити дані}
        {--chunk=500 : Скільки рядків вставляти за один запит}';

    protected $description = 'Перенести всі дані з SQLite-файлу в поточну MySQL-базу (одноразово, при переїзді)';

    private const SOURCE = 'sqlite_migration_source';

    /**
     * Колонки, які є в SQLite, але яких немає в схемі MySQL, — по таблицях.
     *
     * @var array<string, list<string>>
     */
    private array $skippedColumns = [];

    public function handle(): int
    {
        $target = DB::connection();

        if ($target->getDriverName() !== 'mysql') {
            $this->error('Поточна база — не MySQL. Спершу пропишіть DB_CONNECTION=mysql і виконайте php artisan migrate.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("Файлу SQLite немає: {$path}");

            return self::FAILURE;
        }

        config(['database.connections.'.self::SOURCE => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        $source = DB::connection(self::SOURCE);

        try {
            $tables = $this->tablesToCopy($source, $target);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));

        // Порядок таблиць довільний, тож зовнішні ключі на час переносу
        // вимикаємо: цілісність гарантує те, що джерело її вже мало.
        $target->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $copied = $this->copyTable($source, $target, $table, $chunk);
                $this->line(sprintf('  %-28s %d', $table, $copied));
            }
        } finally {
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $mismatches = array_filter(
            $tables,
            fn (string $table) => $source->table($table)->count() !== $target->table($table)->count(),
        );

        if ($mismatches !== []) {
            $this->error('Кількість рядків не збіглась у таблицях: '.implode(', ', $mismatches));

            return self::FAILURE;
        }

        $this->info('Готово: перенесено таблиць — '.count($tables).', кількість рядків у кожній збігається.');

        return self::SUCCESS;
    }

    /**
     * Таблиці джерела, які можна переносити, — або виняток з поясненням, чому
     * переносити не можна взагалі.
     *
     * @return list<string>
     */
    private function tablesToCopy(Connection $source, Connection $target): array
    {
        $sourceMigrations = $source->table('migrations')->pluck('migration')->all();
        $targetMigrations = $target->table('migrations')->pluck('migration')->all();
        $missing = array_diff($sourceMigrations, $targetMigrations);

        if ($missing !== []) {
            throw new RuntimeException('У MySQL не виконано міграцій, які є в SQLite: '.implode(', ', $missing).'. Спершу php artisan migrate.');
        }

        $tables = collect($source->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"))
            ->pluck('name')
            ->reject(fn (string $table) => $table === 'migrations')
            ->values()
            ->all();

        foreach ($tables as $table) {
            if (! Schema::connection($target->getName())->hasTable($table)) {
                throw new RuntimeException("У MySQL немає таблиці {$table}.");
            }

            $extra = array_diff(
                Schema::connection(self::SOURCE)->getColumnListing($table),
                Schema::connection($target->getName())->getColumnListing($table),
            );

            // Залишки ранніх редакцій міграцій (напр. plan_projects.google_spreadsheet_id
            // до переїзду в app_settings): код їх не читає, а схема MySQL будується
            // з актуальних міграцій. Не падаємо, але кажемо вголос, що лишиться позаду.
            if ($extra !== []) {
                $this->skippedColumns[$table] = array_values($extra);

                foreach ($extra as $column) {
                    $filled = $source->table($table)->whereNotNull($column)->count();
                    $this->warn("  Пропускаю {$table}.{$column}: такої колонки немає в міграціях (заповнених рядків: {$filled}).");
                }
            }

            if ($target->table($table)->exists()) {
                throw new RuntimeException("MySQL-таблиця {$table} уже не порожня — переносити можна лише в чисту базу (php artisan migrate:fresh).");
            }
        }

        return $tables;
    }

    private function copyTable(Connection $source, Connection $target, string $table, int $chunk): int
    {
        $copied = 0;
        $buffer = [];

        $target->transaction(function () use ($source, $target, $table, $chunk, &$copied, &$buffer) {
            // rowid є в кожній таблиці SQLite, навіть без первинного ключа
            // (sessions, cache), — порядок вставки лишається тим самим.
            foreach ($source->table($table)->orderByRaw('rowid')->cursor() as $row) {
                $buffer[] = array_diff_key((array) $row, array_flip($this->skippedColumns[$table] ?? []));

                if (count($buffer) >= $chunk) {
                    $target->table($table)->insert($buffer);
                    $copied += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $target->table($table)->insert($buffer);
                $copied += count($buffer);
            }
        });

        return $copied;
    }
}
