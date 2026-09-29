<?php

namespace App\Models;

use App\Jobs\GenerateYawareReport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * «Результат» звіту за день без активності в Yaware (відпустка,
     * лікарняний): в історію і Google Таблицю такий день не пишеться.
     */
    public const EMPTY_DAY_RESULT = 'День без активності в Yaware — історія і Google Таблиця не оновлювались.';

    // Службові ключі summary поруч зі статистикою від воркера. Фронтенд
    // показує summary як є, тож ключі — це заодно й підписи в картці звіту.
    public const SUMMARY_RESULT = 'Результат';

    public const SUMMARY_GOOGLE_SHEET = 'Google Таблиця';

    public const SUMMARY_WARNINGS = 'Попередження';

    // Тека файлів звітів у storage/app; кожен звіт — у підтеці зі своїм id.
    public const FILES_ROOT = 'reports';

    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'summary' => 'array',
            'tasks' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Готовий лише формально: тасок немає (тоді немає ні файлу, ні вкладки)
     * або трекер їх не віддав, чи звіт не дійшов до Google Таблиці. Для
     * працівника це той самий несформований звіт — його треба перегенерувати.
     * День без активності в Yaware (відпустка) неповним не вважається.
     */
    public function isIncomplete(): bool
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            return false;
        }

        $summary = $this->summary ?? [];

        if (($summary[self::SUMMARY_RESULT] ?? null) === self::EMPTY_DAY_RESULT) {
            return false;
        }

        return empty($this->tasks) || empty($summary[self::SUMMARY_GOOGLE_SHEET]);
    }

    /**
     * День без жодної таски в трекері. Знімок тасок звіту: порожній масив —
     * трекер відповів і тасок немає; null — тасок не отримано (трекер не
     * підключено або помилка), і такий день порожнім не вважається, бо його
     * стан невідомий.
     */
    public function hasNoTasks(): bool
    {
        return $this->tasks === [];
    }

    /**
     * Скидає звіт у чергу і ставить генерацію. Повторна генерація
     * ідемпотентна: дані дня перезаписуються.
     */
    public function queueGeneration(): void
    {
        $this->markPending();

        GenerateYawareReport::dispatch($this);
    }

    public function markPending(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'error_message' => null,
        ]);
    }

    /**
     * Тека файлів звіту відносно storage/app.
     */
    public function filesDirectory(): string
    {
        return self::FILES_ROOT.'/'.$this->id;
    }

    /**
     * Останній Excel звіту, поки він є на диску; null — файлу не
     * згенеровано або його вже прибрав reports:prune-files.
     */
    public function excelFile(): ?ReportFile
    {
        $file = $this->files()
            ->where('type', ReportFile::TYPE_EXCEL)
            ->latest('id')
            ->first();

        return $file && is_file($file->absolutePath()) ? $file : null;
    }

    /**
     * Адміністратор бачить усі звіти, працівник — лише свої.
     */
    public function isVisibleTo(User $user): bool
    {
        return $user->isAdmin() || $this->employee?->user_id === $user->id;
    }

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

    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }
}
