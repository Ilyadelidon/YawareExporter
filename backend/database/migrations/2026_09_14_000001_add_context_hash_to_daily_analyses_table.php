<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_analyses', function (Blueprint $table) {
            // Відбиток даних дня, з яких зроблено розбір. Звіт за один і той
            // самий день перегенеровується часто (працівник дописав таску,
            // впала синхронізація), і без цієї позначки кожна перегенерація
            // замовляла новий платний запит до моделі з тим самим результатом.
            $table->string('context_hash', 40)->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('daily_analyses', function (Blueprint $table) {
            $table->dropColumn('context_hash');
        });
    }
};
