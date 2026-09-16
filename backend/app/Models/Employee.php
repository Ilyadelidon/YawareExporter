<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'position', 'email', 'yaware_id', 'yaware_password', 'active', 'dismissed_at'])]
#[Hidden(['yaware_password'])]
class Employee extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'dismissed_at' => 'datetime',
            'yaware_password' => 'encrypted',
        ];
    }

    /**
     * Звільнений — це не те саме, що неактивний. `active` підіймається сам при
     * кожному вдалому вході через Yaware, тож ним двері не зачинити;
     * `dismissed_at` переживає будь-який наступний вхід.
     */
    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /**
     * Звільнення: людина виходить із системи тієї ж секунди й більше не
     * заходить, поки її не поновлять.
     *
     * Історію (звіти, Табель, активності) навмисно не чіпаємо — вона потрібна
     * і після звільнення. Пароль Yaware стираємо: тримати чужі креди після
     * звільнення немає навіщо, а без них не згенерується й звіт.
     */
    public function dismiss(): void
    {
        $this->user?->tokens()->delete();

        $this->update([
            'active' => false,
            'dismissed_at' => now(),
            'yaware_password' => null,
        ]);
    }

    /**
     * Поновлення: знімаємо замок. `active` навмисно лишається знятим — його
     * підніме перший же вдалий вхід через Yaware, як і для всіх інших.
     */
    public function reinstate(): void
    {
        $this->update(['dismissed_at' => null]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
