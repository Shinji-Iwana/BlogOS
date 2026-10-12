<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BlogOS の DB の使用量の、ブログごとの内訳の計算（D-71-09）。計算1回につき1行で、内訳は DatabaseUsageItem。
 */
class DatabaseUsageMeasurement extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    /**
     * 保存する日数（それより古い計算は、古い記録の削除で消す。内訳も一緒に消える）
     */
    public const RETENTION_DAYS = 90;

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DatabaseUsageItem::class);
    }

    public function scheduledTaskRun(): BelongsTo
    {
        return $this->belongsTo(ScheduledTaskRun::class);
    }

    public function prunable(): Builder
    {
        return static::where('measured_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
