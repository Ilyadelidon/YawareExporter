<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Робоча область більше не тримає спільний вебхук порталу: замість нього
     * адміністратор реєструє на порталі локальний застосунок (OAuth 2.0), а
     * кожен працівник авторизується в ньому сам — і читає рівно ті таски,
     * до яких має доступ у самому Бітріксі.
     */
    public function up(): void
    {
        Schema::table('bitrix_workspaces', function (Blueprint $table) {
            // text, бо обидва поля зберігаються зашифрованими (касти в моделі):
            // client_secret — ключ, яким обмінюються коди на токени працівників.
            $table->text('client_id')->nullable()->after('portal_url');
            $table->text('client_secret')->nullable()->after('client_id');
        });

        // Вебхуків більше немає — старі рядки не мігрують, портал підключається заново.
        Schema::table('bitrix_workspaces', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'owner_name']);
        });

        // Робоча область без реквізитів застосунку неробоча: вона показувала б
        // портал підключеним, хоча авторизуватись у ньому нікому не вдасться.
        DB::table('bitrix_workspaces')->delete();
    }

    public function down(): void
    {
        Schema::table('bitrix_workspaces', function (Blueprint $table) {
            $table->text('webhook_url')->nullable();
            $table->string('owner_name')->nullable();
            $table->dropColumn(['client_id', 'client_secret']);
        });
    }
};
