<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'type', 'path', 'original_name'])]
class ReportFile extends Model
{
    // Excel воркера: статистика дня + таски. Єдиний тип файлу звіту.
    public const TYPE_EXCEL = 'combined_excel';

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * Шлях на диску. Файл може бути вже видалений reports:prune-files —
     * наявність перевіряє той, хто його читає.
     */
    public function absolutePath(): string
    {
        return storage_path('app/'.$this->path);
    }
}
