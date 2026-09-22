<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Плани» — те, що команда досі вела в Google Таблиці: проекти, у них
 * розділи й задачі виконавців, а поруч таймлайн по днях («працював над цим»
 * з коментарем) і позначка «працюю зараз».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Архівний проект не зникає з історії, але не заважає в списку.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_project_id', 'employee_id']);
        });

        Schema::create('plan_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Посилання на документ зі списком правок тощо.
            $table->text('note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_project_id')->constrained()->cascadeOnDelete();
            // Розділ необовʼязковий: видалення розділу не має забирати задачі.
            $table->foreignId('plan_section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title', 1000);
            // Посилання на Бітрікс чи файл або коротка примітка — у таблиці це
            // була одна колонка вільного тексту.
            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['plan_project_id', 'position']);
        });

        Schema::create('plan_task_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_task_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['plan_task_id', 'date']);
        });

        // Одна поточна задача на людину, хоч би в скількох проектах вона була.
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('current_plan_task_id')->nullable()->after('dismissed_at')
                ->constrained('plan_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_plan_task_id');
        });

        Schema::dropIfExists('plan_task_days');
        Schema::dropIfExists('plan_tasks');
        Schema::dropIfExists('plan_sections');
        Schema::dropIfExists('plan_project_members');
        Schema::dropIfExists('plan_projects');
    }
};
