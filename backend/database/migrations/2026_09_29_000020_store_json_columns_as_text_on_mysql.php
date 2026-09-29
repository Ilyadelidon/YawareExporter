<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JSON-колонки на MySQL — звичайний LONGTEXT, як і в SQLite.
 *
 * Нативний тип JSON у MySQL зберігає не той рядок, який записав PHP, а власну
 * нормалізовану форму: ключі переставлено за довжиною, після двокрапок —
 * пробіли. Каст 'array' в Eloquent вирішує, чи змінилось поле, порівнянням
 * сирих рядків, тож знімок, записаний тим самим вмістом, завжди виглядав би
 * зміненим: зайвий UPDATE і зсунутий updated_at на кожному прогоні
 * синхронізації, а строге порівняння масивів (SyncsPlanTasks::storeSubtasks)
 * бачило б різницю там, де її немає. JSON-функцій MySQL код не використовує —
 * втрачати нічого.
 *
 * На SQLite (і MariaDB, де JSON і так псевдонім LONGTEXT) міграція нічого не
 * робить.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'reports' => ['summary', 'tasks'],
        'daily_stats' => ['idle_activities'],
        'daily_analyses' => ['result'],
        'users' => ['alert_emails'],
        'plan_projects' => ['sheet_snapshot'],
        'plan_tasks' => ['bitrix_snapshot', 'subtasks', 'trello_snapshot'],
    ];

    public function up(): void
    {
        $this->convert(fn (Blueprint $table, string $column) => $table->longText($column)->nullable()->change());
    }

    public function down(): void
    {
        $this->convert(fn (Blueprint $table, string $column) => $table->json($column)->nullable()->change());
    }

    private function convert(callable $change): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $change) {
                foreach ($columns as $column) {
                    $change($table, $column);
                }
            });
        }
    }
};
