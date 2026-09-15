<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Прив'язка до акаунта Бітрікса переїхала в bitrix_accounts разом із
     * токенами: тепер це не вибір зі списку, а результат авторизації.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bitrix_user_id', 'bitrix_user_name']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bitrix_user_id')->nullable()->after('trello_board_id');
            $table->string('bitrix_user_name')->nullable()->after('bitrix_user_id');
        });
    }
};
