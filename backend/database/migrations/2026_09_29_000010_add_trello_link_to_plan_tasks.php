<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Задача плану ↔ картка Trello з міткою «План» — так само, як із Бітріксом
 * (2026_09_23_000010), лише картка живе на дошці виконавця. Підзадачі тепер
 * бувають з обох трекерів, тож колонка стає спільною й зберігає готове
 * посилання: [{id, title, url}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->string('trello_card_id')->nullable()->unique()->after('bitrix_unlinked_at');
            // Дошка картки: писати в неї можна лише токеном її власника.
            $table->string('trello_board_id')->nullable()->after('trello_card_id');
            $table->json('trello_snapshot')->nullable()->after('trello_board_id');
            $table->boolean('trello_pending')->default(false)->after('trello_snapshot');
            $table->timestamp('trello_unlinked_at')->nullable()->after('trello_pending');
        });

        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->renameColumn('bitrix_subtasks', 'subtasks');
        });

        // Старий формат ({id, title, responsible}) без посилань — наступний
        // прогін синхронізації запише списки заново.
        DB::table('plan_tasks')->update(['subtasks' => null]);
    }

    public function down(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->renameColumn('subtasks', 'bitrix_subtasks');
        });

        DB::table('plan_tasks')->update(['bitrix_subtasks' => null]);

        Schema::table('plan_tasks', function (Blueprint $table) {
            $table->dropUnique(['trello_card_id']);
            $table->dropColumn(['trello_card_id', 'trello_board_id', 'trello_snapshot', 'trello_pending', 'trello_unlinked_at']);
        });
    }
};
