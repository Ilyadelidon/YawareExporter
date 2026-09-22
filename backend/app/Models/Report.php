<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'report_date', 'status', 'summary', 'tasks', 'error_message', 'generated_at'])]
class Report extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // Звіт свідомо не сформовано: у дні лишився час поза тасками, тож
    // працівник має спершу поправити таски в трекері. Це не збій генерації —
    // ops-моніторинг такі звіти не рахує як упалі, а ранковий прогін
    // наступного дня їх не переганяє.
    public const STATUS_BLOCKED = 'blocked';

    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'summary' => 'array',
            'tasks' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }
}
