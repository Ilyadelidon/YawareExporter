<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // text, бо токен зберігається зашифрованим (каст 'encrypted' у моделі).
            $table->text('trello_token')->nullable()->after('role');
            $table->string('trello_member_username')->nullable()->after('trello_token');
            $table->string('trello_board_id')->nullable()->after('trello_member_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['trello_token', 'trello_member_username', 'trello_board_id']);
        });
    }
};
