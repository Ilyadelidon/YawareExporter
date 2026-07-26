<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Персональна Google Таблиця користувача; null = вивантаження
            // у глобальну GOOGLE_SPREADSHEET_ID з .env (legacy-fallback).
            $table->string('google_spreadsheet_id')->nullable()->after('trello_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_spreadsheet_id');
        });
    }
};
