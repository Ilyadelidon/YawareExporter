<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Пошта керівника для листів про критичні порушення. Свідомо окреме
            // поле, а не email акаунта: керівник читає такі листи там, де йому
            // зручно (спільна скринька відділу, особиста пошта), і має право
            // вимкнути їх зовсім, не чіпаючи логін.
            $table->string('alert_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alert_email');
        });
    }
};
