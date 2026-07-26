<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Знімок Trello-тасок на момент генерації: готовий звіт показує саме їх,
            // незалежно від того, яку дошку користувач вибрав пізніше. null — знімка
            // немає (старий звіт або Trello був недоступний), тоді фронтенд бере живі дані.
            $table->json('trello_tasks')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('trello_tasks');
        });
    }
};
