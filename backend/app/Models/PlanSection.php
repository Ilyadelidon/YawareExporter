<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_project_id', 'name', 'note', 'position'])]
class PlanSection extends Model
{
    public function project(): BelongsTo
    {
        return $this->belongsTo(PlanProject::class, 'plan_project_id');
    }
}
