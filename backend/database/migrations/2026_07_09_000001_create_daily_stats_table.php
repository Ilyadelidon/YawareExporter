<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('first_action')->nullable();
            $table->string('last_action')->nullable();
            $table->unsignedInteger('lateness_seconds')->default(0);
            $table->unsignedInteger('left_early_seconds')->default(0);
            $table->unsignedInteger('productive_seconds')->default(0);
            $table->unsignedInteger('unproductive_seconds')->default(0);
            $table->unsignedInteger('neutral_seconds')->default(0);
            $table->unsignedInteger('total_seconds')->default(0);
            $table->json('idle_activities')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
