<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Одноразова чистка: дні без активності більше не пишуться в історію
// (GenerateYawareReport пропускає їх), а вже накопичені нульові рядки
// спотворювали лічильник «Днів» у Табелі.
return new class extends Migration
{
    public function up(): void
    {
        $emptyDays = DB::table('daily_stats')
            ->where('total_seconds', 0)
            ->whereNull('idle_activities')
            ->get(['employee_id', 'date']);

        foreach ($emptyDays as $day) {
            DB::table('activity_entries')
                ->where('employee_id', $day->employee_id)
                ->whereDate('date', $day->date)
                ->delete();
        }

        DB::table('daily_stats')
            ->where('total_seconds', 0)
            ->whereNull('idle_activities')
            ->delete();
    }

    public function down(): void
    {
        // Видалені порожні дні не відновлюються — їх можна перегенерувати звітом.
    }
};
