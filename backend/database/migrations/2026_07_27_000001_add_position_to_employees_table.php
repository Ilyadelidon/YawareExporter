<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Посада потрібна AI-аналізу: без неї модель не може судити, чи
            // доречні відвідані сайти для роботи цієї людини. null — посаду
            // ще не заповнили, аналіз тоді судить лише за тасками дня.
            $table->string('position')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
