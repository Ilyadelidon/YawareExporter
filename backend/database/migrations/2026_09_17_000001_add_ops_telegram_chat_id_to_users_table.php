<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Окремий чат для технічних сповіщень адміністратора (збої, стан ранкового
 * прогону). Не той самий, що telegram_chat_id: особисті сповіщення про звіти
 * й службові тривоги адмін може хотіти в різних місцях — або одне без іншого.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ops_telegram_chat_id')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ops_telegram_chat_id');
        });
    }
};
