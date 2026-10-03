<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BlogOS 全体の設定（D-49）。行がない設定は、config の既定で動く。
 */
class SystemSetting extends Model
{
    protected $guarded = ['id'];

    /**
     * 設定の値（行がなければ $default）
     */
    public static function value(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
