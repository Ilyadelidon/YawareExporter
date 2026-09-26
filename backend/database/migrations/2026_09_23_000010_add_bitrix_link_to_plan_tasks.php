<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задача плану ↔ задача Бітрікса з тегом «План». Зліпок — те, на чому
 * обидві сторони востаннє зійшлися: лише порівнявши з ним, можна сказати,
 * хто саме змінив поле — сервіс чи Бітрікс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->string('bitrix_task_id')->nullable()->unique()->after('position');
            $table->json('bitrix_snapshot')->nullable()->after('bitrix_task_id');
            // Зміна в сервісі, яку ще не вдалося донести до Бітрікса — її
            // підхопить наступний прогін синхронізації.
            $table->boolean('bitrix_pending')->default(false)->after('bitrix_snapshot');
            // Задачу видалили в Бітріксі або зняли з неї тег «План»: у плані
            // вона лишається, але вже ні з чим не звʼязана.
            $table->timestamp('bitrix_unlinked_at')->nullable()->after('bitrix_pending');
        });
    }

    public function down(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->dropUnique(['bitrix_task_id']);
            $table->dropColumn(['bitrix_task_id', 'bitrix_snapshot', 'bitrix_pending', 'bitrix_unlinked_at']);
        });
    }
};
