<?php

namespace App\Models;

use App\Enums\SyncIssueType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 人の対応が必要な問題（sync_issues）。BLOGOS_DATABASE.md 10-3。
 */
class SyncIssue extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_type'        => SyncIssueType::class,
            'first_detected_at' => 'datetime',
            'last_detected_at'  => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * 解決から1年を過ぎた記録を定期処理で削除する。未解決のものは削除しない（D-04-08）。
     */
    public function prunable(): Builder
    {
        return static::whereNotNull('resolved_at')->where('resolved_at', '<', now()->subYear());
    }
}
