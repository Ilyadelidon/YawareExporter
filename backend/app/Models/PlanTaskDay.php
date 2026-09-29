<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable(['plan_task_id', 'date', 'comment'])]
class PlanTaskDay extends Model
{
    /**
     * Дата пишеться голим Y-m-d. Каст 'date' зберіг би «Y-m-d 00:00:00», і в
     * SQLite тоді ні where('date', '2026-09-16'), ні унікальний індекс уже не
     * збігаються з рядком, записаним інакше.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value)->startOfDay(),
            set: fn (mixed $value) => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PlanTask::class, 'plan_task_id');
    }

    /**
     * Відмітки задач за місяць. Порівняння по самій колонці, а не whereDate:
     * так працює унікальний індекс (plan_task_id, date).
     *
     * @param  iterable<int>  $taskIds
     */
    public function scopeForTasksInMonth(Builder $query, iterable $taskIds, CarbonImmutable $month): Builder
    {
        return $query->whereIn('plan_task_id', $taskIds)
            ->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()]);
    }

    /**
     * Останній робочий день кожної задачі за весь час — щоб фільтр «активні»
     * не ховав задачу лише тому, що цього місяця її не чіпали.
     *
     * @param  iterable<int>  $taskIds
     * @return Collection<int, string> id задачі => Y-m-d
     */
    public static function lastWorkedDates(iterable $taskIds): Collection
    {
        return self::whereIn('plan_task_id', $taskIds)
            ->groupBy('plan_task_id')
            ->selectRaw('plan_task_id, max(date) as last_date')
            ->pluck('last_date', 'plan_task_id')
            ->map(fn ($date) => substr((string) $date, 0, 10));
    }
}
