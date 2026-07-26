<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'type', 'path', 'original_name'])]
class ReportFile extends Model
{
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
