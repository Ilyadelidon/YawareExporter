<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Технічних чатів у адміністратора може бути кілька: особистий, спільний чат
 * команди підтримки, окрема група на чергування. Один стовпчик у users це не
 * вміщав, тож привʼязки переїхали в свою таблицю.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id');
            // Назва групи або імʼя з Telegram: у списку з кількох чатів самий
            // лише числовий id нічого адміністратору не каже.
            $table->string('title')->nullable();
            $table->timestamps();
            // Двічі підключити той самий чат нема сенсу — повторний Start лише
            // оновить назву.
            $table->unique(['user_id', 'chat_id']);
        });

        // Уже підключені чати не мають загубитись при оновленні. Назву не
        // вигадуємо з імені акаунта — це різні речі; порожню запитаємо в
        // самого Telegram при першому відкритті налаштувань.
        $now = now();
        $existing = DB::table('users')
            ->whereNotNull('ops_telegram_chat_id')
            ->get(['id', 'ops_telegram_chat_id']);

        foreach ($existing as $user) {
            DB::table('ops_telegram_chats')->insert([
                'user_id' => $user->id,
                'chat_id' => $user->ops_telegram_chat_id,
                'title' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ops_telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ops_telegram_chat_id')->nullable()->after('telegram_chat_id');
        });

        // У стовпчик вміщається лише один чат — лишаємо найперший підключений.
        $first = DB::table('ops_telegram_chats')->orderBy('id')->get(['user_id', 'chat_id']);
        $seen = [];

        foreach ($first as $chat) {
            if (isset($seen[$chat->user_id])) {
                continue;
            }

            $seen[$chat->user_id] = true;
            DB::table('users')->where('id', $chat->user_id)->update(['ops_telegram_chat_id' => $chat->chat_id]);
        }

        Schema::dropIfExists('ops_telegram_chats');
    }
};
