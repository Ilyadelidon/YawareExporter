<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // activity — вердикт по домену чи застосунку; fact — робочий контекст
            // працівника (інструменти, графік, специфіка посади).
            $table->string('kind')->default('activity');
            $table->string('name');
            $table->string('verdict')->nullable();
            $table->text('note')->nullable();
            // admin-рядок модель не перезаписує — інакше помилковий вердикт
            // не було б як виправити назавжди.
            $table->string('source')->default('ai');
            $table->unsignedInteger('occurrences')->default(1);
            $table->date('last_seen_at')->nullable();
            // Коли вердикт востаннє встановлювався: старші за RECHECK_DAYS
            // у промпт не йдуть, тому домен перевіряється заново.
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_memories');
    }
};
