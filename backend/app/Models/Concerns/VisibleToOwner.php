<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Записи працівника (через зв'язок employee): адміністратор бачить усі,
 * працівник — лише свої.
 */
trait VisibleToOwner
{
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->whereRelation('employee', 'user_id', $user->id);
        }
    }
}
