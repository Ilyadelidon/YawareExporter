<?php

namespace App\Services;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

class ReportHistoryService
{
    /**
     * Складає структуровані дані воркера (history-data-*.json) в історичні
     * таблиці. Повторна генерація звіту за той самий день перезаписує дані.
     *
     * @param array{stats?: array<string, mixed>, activities?: array<int, array<string, mixed>>, idle_activities?: ?array<int, array<int, string>>} $history
     */
    public function store(Report $report, array $history): DailyStat
    {
        $stats = $history['stats'] ?? [];
        $date = $report->report_date->toDateString();

        return DB::transaction(function () use ($report, $history, $stats, $date) {
            $dailyStat = DailyStat::updateOrCreate(
                ['employee_id' => $report->employee_id, 'date' => $date],
                [
                    'first_action' => $stats['first_action'] ?? null,
                    'last_action' => $stats['last_action'] ?? null,
                    'lateness_seconds' => (int) ($stats['lateness_seconds'] ?? 0),
                    'left_early_seconds' => (int) ($stats['left_early_seconds'] ?? 0),
                    'productive_seconds' => (int) ($stats['productive_seconds'] ?? 0),
                    'unproductive_seconds' => (int) ($stats['unproductive_seconds'] ?? 0),
                    'neutral_seconds' => (int) ($stats['neutral_seconds'] ?? 0),
                    'total_seconds' => (int) ($stats['total_seconds'] ?? 0),
                    'idle_activities' => $history['idle_activities'] ?? null,
                ],
            );

            ActivityEntry::where('employee_id', $report->employee_id)
                ->where('date', $date)
                ->delete();

            $now = now();
            $rows = collect($history['activities'] ?? [])
                ->filter(fn ($activity) => is_array($activity) && trim((string) ($activity['name'] ?? '')) !== '')
                ->map(fn (array $activity) => [
                    'employee_id' => $report->employee_id,
                    'date' => $date,
                    'productivity' => (string) ($activity['productivity'] ?? 'neutral'),
                    'name' => mb_substr(trim((string) $activity['name']), 0, 500),
                    'category' => $activity['category'] ?? null,
                    'duration_seconds' => max(0, (int) ($activity['duration_seconds'] ?? 0)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach ($rows->chunk(200) as $chunk) {
                ActivityEntry::insert($chunk->values()->all());
            }

            return $dailyStat;
        });
    }

    /**
     * Прибирає день працівника з історії. Потрібно для перегенерації, коли
     * день виявився без активності: раніше він міг записатись із даними,
     * а порожні дні в daily_stats не зберігаються.
     */
    public function forgetDay(Report $report): void
    {
        $date = $report->report_date->toDateString();

        DB::transaction(function () use ($report, $date) {
            DailyStat::where('employee_id', $report->employee_id)
                ->where('date', $date)
                ->delete();

            ActivityEntry::where('employee_id', $report->employee_id)
                ->where('date', $date)
                ->delete();
        });
    }
}
