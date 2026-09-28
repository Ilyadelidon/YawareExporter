<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Підзадачі задачі плану в Бітріксі — [{id, title, responsible}]. Лише для
 * перегляду в плані: їх переписує прогін синхронізації.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->json('bitrix_subtasks')->nullable()->after('bitrix_unlinked_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->dropColumn('bitrix_subtasks');
        });
    }
};
