<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позначка про звільнення. Окрема від `active` саме тому, що `active`
 * підіймається сам при кожному вдалому вході через Yaware — так і задумано
 * (є людина в Yaware, значить, працює). А звільнення має пережити будь-який
 * наступний вхід, тож йому потрібен власний прапорець.
 *
 * Дата, а не булеве поле: заразом видно, коли саме людину звільнили.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
