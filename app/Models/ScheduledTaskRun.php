<?php

namespace App\Models;

use App\Support\ScheduledTasks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 定期実行の実行の記録（D-44）。開始・終了・かかった時間・処理件数などを残す。
 */
class ScheduledTaskRun extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    public const STATUSES = ['running' => '実行中', 'succeeded' => '成功', 'failed' => '失敗'];

    public const TRIGGERS = ['scheduled' => '定期実行', 'manual' => '今すぐ実行'];

    /**
     * これより長く「実行中」のままの記録は、途中で止まったとみなして表示する
     */
    public const STALE_HOURS = 3;

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at'    => 'datetime',
            'finished_at'   => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function label(): string
    {
        return ScheduledTasks::get($this->task_key)['label'] ?? $this->task_key;
    }

    public function statusLabel(): string
    {
        if ($this->isStale()) {
            return '終わっていない（途中で止まった可能性）';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isStale(): bool
    {
        return $this->status === 'running' && $this->started_at->lt(now()->subHours(self::STALE_HOURS));
    }

    /**
     * 予定の時刻からの開始の遅れ（秒。定期実行だけ）
     */
    public function delaySeconds(): ?int
    {
        return $this->scheduled_for !== null ? max(0, (int) $this->scheduled_for->diffInSeconds($this->started_at)) : null;
    }

    /**
     * 実行の記録は1年で削除する（同期の記録と同じ。D-04-08）
     */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subYear());
    }
}
