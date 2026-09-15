<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Портал Бітрікс24 команди: адреса порталу + реквізити локального застосунку
 * (OAuth 2.0), який на ньому зареєстрував адміністратор. Сам доступ до тасок
 * дає не ця робоча область, а особистий токен кожного працівника —
 * див. [[BitrixAccount]]. Активною вважається єдина (остання) робоча область.
 */
#[Fillable(['portal_url', 'client_id', 'client_secret', 'connected_by'])]
#[Hidden(['client_id', 'client_secret'])]
class BitrixWorkspace extends Model
{
    protected function casts(): array
    {
        return [
            // client_secret дозволяє обміняти код авторизації на токен працівника,
            // тож у БД обидва реквізити лежать зашифрованими.
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * Портал один на команду, тож підключення нового замінює попередній разом
     * з усіма виданими на старому застосунку токенами працівників.
     */
    public static function connect(array $attributes): self
    {
        static::query()->delete();
        BitrixAccount::query()->delete();

        return static::create($attributes);
    }

    /** Хост порталу (team.bitrix24.ua) — з ним звіряємо домен із відповіді OAuth. */
    public function portalHost(): string
    {
        return (string) parse_url($this->portal_url, PHP_URL_HOST);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
