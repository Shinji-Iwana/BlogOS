<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BlogOS の DB の使用量の内訳（D-71-09）。計算1回の中の「テーブル × ブログ」ごとの件数と容量の見積もり（blog_id が null は共通）。
 */
class DatabaseUsageItem extends Model
{
    protected $guarded = ['id'];

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(DatabaseUsageMeasurement::class, 'database_usage_measurement_id');
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }
}
