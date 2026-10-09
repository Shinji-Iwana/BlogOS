<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PageSpeed Insights の測定の記録（D-78）。1つの URL を、携帯かデスクトップで1回測ったごとに1行。
 */
class PageSpeedRun extends Model
{
    use Prunable;

    protected $table = 'pagespeed_runs';

    protected $guarded = ['id'];

    public const STRATEGIES = ['mobile' => '携帯', 'desktop' => 'デスクトップ'];

    public const STATUSES = ['running' => '実行中', 'succeeded' => '成功', 'failed' => '失敗'];

    public const TRIGGERS = ['scheduled' => '定期実行', 'manual' => '今すぐ実行'];

    /** 実際の利用者の値の判定（Chrome の利用者の記録の overall_category） */
    public const FIELD_CATEGORIES = ['FAST' => '良好', 'AVERAGE' => '改善が必要', 'SLOW' => '不良'];

    /**
     * 「実行中」のままこれより長い記録は、途中で止まったとみなして表示する（1回の測定の待ち時間より十分に長く）
     */
    public const STALE_MINUTES = 30;

    protected function casts(): array
    {
        return [
            'cls'           => 'float',
            'field_cls'     => 'float',
            'origin_cls'    => 'float',
            'failed_audits' => 'array',
            'started_at'    => 'datetime',
            'finished_at'   => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function strategyLabel(): string
    {
        return self::STRATEGIES[$this->strategy] ?? $this->strategy;
    }

    public function statusLabel(): string
    {
        if ($this->status === 'running' && $this->started_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
            return '終わっていない（途中で止まった可能性）';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? $this->trigger;
    }

    /**
     * 測った対象の名前（記事のタイトル。記事でなければ「トップページ」）。post・page を読み込んでおく
     */
    public function targetLabel(): string
    {
        if ($this->post_id === null && $this->page_id === null) {
            return 'トップページ';
        }

        return (string) ($this->post?->title_raw ?? $this->page?->title_raw ?? '（削除された記事）');
    }

    /**
     * 測定の記録は、ほかの同期の記録と同じく1年で削除する
     */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subYear());
    }
}
