<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Командний портал Бітрікс24: один вхідний вебхук на всіх працівників.
 * Активною вважається єдина (остання) робоча область.
 */
#[Fillable(['portal_url', 'webhook_url', 'owner_name', 'connected_by'])]
#[Hidden(['webhook_url'])]
class BitrixWorkspace extends Model
{
    protected function casts(): array
    {
        return [
            // Вебхук — повноцінний ключ до REST порталу, у БД лежить зашифрованим.
            'webhook_url' => 'encrypted',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * Портал один на команду, тож підключення нового замінює попередній.
     */
    public static function connect(array $attributes): self
    {
        static::query()->delete();

        return static::create($attributes);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
