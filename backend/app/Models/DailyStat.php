<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
