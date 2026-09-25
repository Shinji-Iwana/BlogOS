<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 同期の実行記録（sync_runs）。BLOGOS_DATABASE.md 10-1。
 */
class SyncRun extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger'     => SyncTrigger::class,
            'status'      => SyncStatus::class,
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(SyncRunResource::class);
    }

    /**
     * 保存期間（1年）を過ぎた記録を定期処理で削除する（D-04-08）。
     */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subYear());
    }
}
