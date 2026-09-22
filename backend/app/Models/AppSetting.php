<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    public const PLANS_SPREADSHEET_ID = 'plans.google_spreadsheet_id';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function get(string $key): ?string
    {
        return static::find($key)?->value;
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value === null) {
            static::whereKey($key)->delete();

            return;
        }

        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
