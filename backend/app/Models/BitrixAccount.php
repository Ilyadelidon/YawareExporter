<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Особистий доступ працівника до порталу Бітрікса: токени, видані йому самим
 * Бітріксом після авторизації в локальному застосунку команди ([[BitrixWorkspace]]).
 * Запити до REST ідуть від його імені, тож чужі таски недосяжні в принципі.
 */
#[Fillable([
    'user_id', 'bitrix_user_id', 'bitrix_user_name', 'bitrix_email', 'member_id',
    'client_endpoint', 'access_token', 'refresh_token', 'expires_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class BitrixAccount extends Model
{
    /**
     * Запас перед формальним закінченням access_token: година життя, тож
     * хвилини вистачає, щоб довгий звіт не впав посеред виконання.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function needsRefresh(): bool
    {
        return $this->expires_at === null
            || $this->expires_at->subSeconds(self::EXPIRY_MARGIN_SECONDS)->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
