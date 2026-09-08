<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Знімок тасок у звіті більше не обов'язково з Trello — джерелом може бути
     * і Бітрікс24, тож колонка перестає називатися по імені одного провайдера.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('trello_tasks', 'tasks');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('tasks', 'trello_tasks');
        });
    }
};
