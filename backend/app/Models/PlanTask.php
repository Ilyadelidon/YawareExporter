<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['plan_project_id', 'plan_section_id', 'employee_id', 'title', 'note', 'status', 'position'])]
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
