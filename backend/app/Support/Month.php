<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class Month
{
    /**
     * Перше число місяця з рядка Y-m (без нього — поточний місяць). `!`
     * обнуляє решту полів: без нього день береться з сьогоднішньої дати,
     * і 30 числа «2026-02» стає березнем.
     */
    public static function parse(?string $month): CarbonImmutable
    {
        return $month === null
            ? CarbonImmutable::now()->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', $month);
    }
}
