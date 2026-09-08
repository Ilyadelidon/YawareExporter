<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_analyses', function (Blueprint $table) {
            // Коли по цьому дню вже пішов лист керівнику. Позначка потрібна, бо
            // розбір дня перезапускається (кнопкою «Проаналізувати ще раз», а то
            // й перегенерацією звіту) — без неї керівник отримував би той самий
            // день повторно.
            $table->timestamp('alerted_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('daily_analyses', function (Blueprint $table) {
            $table->dropColumn('alerted_at');
        });
    }
};
