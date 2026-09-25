<?php

namespace App\Models;

use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Enums\PushState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 反映記録（BLOGOS_DATABASE.md 10-4、D-01-09、D-04-02）。
 *
 * 状態は pending → sent → wp_succeeded → completed と進む。失敗は failed、結果が分からない場合は unknown。
 */
class WordPressPushOperation extends Model
{
    use Prunable;

    protected $table = 'wordpress_push_operations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resource_type'         => PushResourceType::class,
            'operation'             => PushOperationType::class,
            'state'                 => PushState::class,
            'request_summary'       => 'array',
            'base_values'           => 'array',
            'response_modified_gmt' => 'datetime',
            'sent_at'               => 'datetime',
            'wp_succeeded_at'       => 'datetime',
            'completed_at'          => 'datetime',
            'failed_at'             => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ArticleDraft::class, 'article_draft_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * 反映の対象（新規作成で、まだ結果が確定していない場合は NULL）
     */
    public function target(): ?WordPressRecord
    {
        return $this->post ?? $this->page ?? $this->category ?? $this->tag ?? $this->media;
    }

    /**
     * 画面に表示する対象の名前
     */
    public function targetLabel(): string
    {
        $target = $this->target();

        return (string) ($target?->title_raw ?? $target?->name ?? $this->draft?->title_raw ?? '-');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * 完了から1年を過ぎた記録を定期処理で削除する。完了していないものは削除しない（DATABASE 14章）。
     */
    public function prunable(): Builder
    {
        return static::where('state', PushState::Completed)->where('completed_at', '<', now()->subYear());
    }
}
