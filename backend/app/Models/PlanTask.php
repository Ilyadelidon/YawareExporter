<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'plan_project_id', 'plan_section_id', 'employee_id', 'title', 'note', 'status', 'position',
    'bitrix_task_id', 'bitrix_snapshot', 'bitrix_pending', 'bitrix_unlinked_at',
    'trello_card_id', 'trello_board_id', 'trello_snapshot', 'trello_pending', 'trello_unlinked_at',
    'subtasks',
])]
class PlanTask extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_REVIEW = 'review';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_RECURRING = 'recurring';

    public const STATUS_DONE = 'done';

    public const STATUS_NOT_RELEVANT = 'not_relevant';

    /** Підписи — ті самі, що були у випадайці Google Таблиці. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Очікує виконання',
        self::STATUS_IN_PROGRESS => 'В роботі',
        self::STATUS_REVIEW => 'На перевірці',
        self::STATUS_PAUSED => 'Пауза',
        self::STATUS_RECURRING => 'Регулярна',
        self::STATUS_DONE => 'Виконано',
        self::STATUS_NOT_RELEVANT => 'Поки не актуально',
    ];

    /** Над задачею в цих статусах «зараз» ніхто не працює. */
    public const INACTIVE_STATUSES = [
        self::STATUS_PAUSED,
        self::STATUS_DONE,
        self::STATUS_NOT_RELEVANT,
    ];

    protected function casts(): array
    {
        return [
            'bitrix_snapshot' => 'array',
            'bitrix_pending' => 'boolean',
            'bitrix_unlinked_at' => 'datetime',
            'trello_snapshot' => 'array',
            'trello_pending' => 'boolean',
            'trello_unlinked_at' => 'datetime',
            'subtasks' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(PlanProject::class, 'plan_project_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PlanSection::class, 'plan_section_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(PlanTaskDay::class)->orderBy('date');
    }
}
