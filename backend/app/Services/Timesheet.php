<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Табель за місяць: матриця «працівник × дні» з робочим часом (без
 * непродуктивного) із daily_stats.
 */
class Timesheet
{
    /**
     * @return Collection<int, array{id: int, name: string, days: object, total_seconds: int, days_worked: int}>
     */
    public function rows(CarbonImmutable $month): Collection
    {
        $stats = DailyStat::betweenDates($month->toDateString(), $month->endOfMonth()->toDateString())
            ->get()
            ->groupBy('employee_id');

        return $this->employees($stats->keys())
            ->map(fn (Employee $employee) => $this->row($employee, $stats[$employee->id] ?? collect()))
            ->values();
    }

    /**
     * Активні працівники плюс ті, у кого є дані за місяць (навіть якщо їх уже
     * деактивували чи звільнили).
     */
    private function employees(Collection $withStats): Collection
    {
        return Employee::query()
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $withStats))
            ->orderBy('name')
            ->get();
    }

    private function row(Employee $employee, Collection $stats): array
    {
        // У табель іде лише робочий час: непродуктивний віднімається.
        $days = $stats->mapWithKeys(fn (DailyStat $stat) => [
            $stat->date->toDateString() => $stat->workSeconds(),
        ]);

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'days' => (object) $days->all(),
            'total_seconds' => (int) $days->sum(),
            // Нульові дні (записані до того, як порожні дні перестали
            // потрапляти в історію) не рахуються відпрацьованими.
            'days_worked' => $days->filter(fn (int $seconds) => $seconds > 0)->count(),
        ];
    }
}
