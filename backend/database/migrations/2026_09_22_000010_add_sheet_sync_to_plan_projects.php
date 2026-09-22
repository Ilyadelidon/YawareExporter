<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Зліпок останнього вивантаження проекту в Google Таблицю. Без нього не
 * відрізнити правку, зроблену людиною в таблиці, від того, що ми самі туди
 * записали: саме порівняння «аркуш / зліпок / база» і вирішує, що підтягувати
 * назад, а де змінилося в сервісі й таблиця має поступитись.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_projects', function (Blueprint $table) {
            $table->json('sheet_snapshot')->nullable()->after('archived_at');
            $table->timestamp('sheet_synced_at')->nullable()->after('sheet_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('plan_projects', function (Blueprint $table) {
            $table->dropColumn(['sheet_snapshot', 'sheet_synced_at']);
        });
    }
};
