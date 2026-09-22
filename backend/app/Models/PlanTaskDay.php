<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
