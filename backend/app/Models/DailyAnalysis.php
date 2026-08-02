<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'date',
    'status',
    'provider',
    'result',
    'model',
    'input_tokens',
    'output_tokens',
    'error_message',
    'generated_at',
])]
class DailyAnalysis extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'result' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
