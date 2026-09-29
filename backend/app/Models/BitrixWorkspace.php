<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($attributes) {
            static::disconnect();

            return static::create($attributes);
        });
    }

    /**
     * Прибирає портал команди разом з токенами працівників: видані на ньому
     * токени без застосунку, що їх видав, ні до чого не придатні.
     */
    public static function disconnect(): void
    {
        DB::transaction(function () {
            static::query()->delete();
            BitrixAccount::query()->delete();
        });
    }

    /** Хост порталу (team.bitrix24.ua) — з ним звіряємо домен із відповіді OAuth. */
    public function portalHost(): string
    {
        return (string) parse_url($this->portal_url, PHP_URL_HOST);
    }

    /** Особистий список задач працівника на порталі. */
    public function userTasksUrl(string $bitrixUserId): string
    {
        return rtrim($this->portal_url, '/')."/company/personal/user/{$bitrixUserId}/tasks/";
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
