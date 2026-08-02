<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_analyses', function (Blueprint $table) {
            // Яким AI зроблено розбір: anthropic | deepseek. Потрібне не лише
            // для показу — за ним видно, чи порівнюємо ми зіставні результати.
            $table->string('provider')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('daily_analyses', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
