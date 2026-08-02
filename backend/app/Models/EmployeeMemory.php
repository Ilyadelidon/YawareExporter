<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Що AI вже знає про робочий контекст конкретного працівника: вердикти по
 * доменах і застосунках плюс фактичні нотатки (інструменти, графік).
 *
 * Сенс — не питати те саме щодня: без пам'яті модель ходить у web_search за
 * тим самим доменом кожного разу, і саме ці пошуки складають ~2/3 рахунку.
 */
#[Fillable([
    'employee_id',
    'kind',
    'name',
    'verdict',
    'note',
    'source',
    'occurrences',
    'last_seen_at',
    'checked_at',
])]
class EmployeeMemory extends Model
{
    public const KIND_ACTIVITY = 'activity';

    public const KIND_FACT = 'fact';

    public const SOURCE_AI = 'ai';

    public const SOURCE_ADMIN = 'admin';

    public const VERDICTS = ['work_related', 'personal', 'unknown'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'date:Y-m-d',
            'checked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
