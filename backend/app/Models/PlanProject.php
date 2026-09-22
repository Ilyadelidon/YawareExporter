<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'archived_at', 'sheet_snapshot', 'sheet_synced_at'])]
class PlanProject extends Model
{
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            // Зліпок останнього вивантаження: що саме ми записали в аркуш.
            // База для злиття правок, зроблених людиною просто в таблиці.
            'sheet_snapshot' => 'array',
            'sheet_synced_at' => 'datetime',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'plan_project_members')->withTimestamps();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PlanSection::class)->orderBy('position')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(PlanTask::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Проекти, які бачить користувач: адміністратор — усі, працівник — лише
     * ті, куди його додали.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('members', fn (Builder $members) => $members->where('employees.user_id', $user->id));
    }

    public function hasMember(?Employee $employee): bool
    {
        return $employee !== null && $this->members()->whereKey($employee->id)->exists();
    }
}
