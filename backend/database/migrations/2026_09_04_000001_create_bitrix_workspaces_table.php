<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Робоча область Бітрікс24 одна на всю команду: портал підключає адміністратор
     * вхідним вебхуком, а працівники лише вказують свій акаунт на цьому порталі.
     * Таблиця тримає щонайбільше один активний рядок.
     */
    public function up(): void
    {
        Schema::create('bitrix_workspaces', function (Blueprint $table) {
            $table->id();
            // Схема + хост порталу (https://team.bitrix24.ua) — для посилань на таски.
            $table->string('portal_url');
            // text, бо вебхук зберігається зашифрованим (каст 'encrypted' у моделі):
            // це повноцінний ключ доступу до REST порталу.
            $table->text('webhook_url');
            // Чий вебхук — від імені цього користувача Бітрікса йдуть усі запити.
            $table->string('owner_name')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix_workspaces');
    }
};
