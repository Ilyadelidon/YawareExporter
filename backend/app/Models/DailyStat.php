<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable([
    'employee_id',
    'date',
    'first_action',
    'last_action',
    'lateness_seconds',
    'left_early_seconds',
    'productive_seconds',
    'unproductive_seconds',
    'neutral_seconds',
    'total_seconds',
    'idle_activities',
])]
class DailyStat extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'idle_activities' => 'array',
        ];
    }

    /**
     * Робочий час дня: із загального віднімається непродуктивний.
     * Так рахують і Табель, і підсумки Статистики.
     */
    public function workSeconds(): int
    {
        return max(0, (int) $this->total_seconds - (int) $this->unproductive_seconds);
    }

    /**
     * Підсумки за вибірку днів.
     *
     * @param  Collection<int, self>  $stats
     * @return array<string, int>
     */
    public static function totals(Collection $stats): array
    {
        return [
            'days' => $stats->count(),
            'productive_seconds' => (int) $stats->sum('productive_seconds'),
            'unproductive_seconds' => (int) $stats->sum('unproductive_seconds'),
            'neutral_seconds' => (int) $stats->sum('neutral_seconds'),
            'total_seconds' => (int) $stats->sum('total_seconds'),
            'work_seconds' => (int) $stats->sum(fn (self $stat) => $stat->workSeconds()),
            'lateness_seconds' => (int) $stats->sum('lateness_seconds'),
        ];
    }

    /**
     * Дні з $from по $to включно. Порівняння по самій колонці, а не whereDate:
     * обгортка в DATE()/strftime() вимикає індекс (employee_id, date) і змушує
     * читати таблицю цілком.
     */
    public function scopeBetweenDates(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('date', [$from, $to]);
    }

    /**
     * Адміністратор бачить статистику всіх, працівник — лише свою.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->whereRelation('employee', 'user_id', $user->id);
        }
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
