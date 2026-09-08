<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Звідки брати таски для звіту: trello | bitrix. Усі наявні користувачі
            // лишаються на Trello — інтеграція для них не змінюється.
            $table->string('task_provider')->default('trello')->after('role');
            // Акаунт працівника на командному порталі Бітрікса (RESPONSIBLE_ID тасок).
            $table->string('bitrix_user_id')->nullable()->after('trello_board_id');
            $table->string('bitrix_user_name')->nullable()->after('bitrix_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['task_provider', 'bitrix_user_id', 'bitrix_user_name']);
        });
    }
};
