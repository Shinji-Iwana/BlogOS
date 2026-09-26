<?php

namespace App\Models;

use App\Enums\DraftOrigin;
use App\Enums\DraftState;
use App\Enums\PushResourceType;
use App\Enums\PushState;
use App\Enums\RevisionScope;
use App\Models\Histories\ArticleDraftHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 編集案（BLOGOS_DATABASE.md 9-1、D-01-06〜D-01-08）。
 *
 * 既存記事の改修では post_id / page_id のどちらかを持ち、新規記事では両方NULLで target_type だけを持つ。
 */
class ArticleDraft extends Model
{
    protected $guarded = ['id'];

    /**
     * 既定値（DBの既定値と同じ。作成直後のModelでも値を持たせるため）
     */
    protected $attributes = [
        'state'        => 'editing',
        'origin'       => 'human',
        'human_edited' => false,
    ];

    /**
     * 内容の列（履歴と反映の対象）
     */
    public const CONTENT_COLUMNS = [
        'title_raw', 'content_raw', 'excerpt_raw', 'meta_description', 'slug', 'status',
        'wordpress_category_ids', 'wordpress_tag_ids', 'wordpress_featured_media_id',
    ];

    protected function casts(): array
    {
        return [
            'target_type'                 => PushResourceType::class,
            'state'                       => DraftState::class,
            'origin'                      => DraftOrigin::class,
            'revision_scope'              => RevisionScope::class,
            'base_wordpress_modified_gmt' => 'datetime',
            'wordpress_category_ids'      => 'array',
            'wordpress_tag_ids'           => 'array',
            'human_edited'                => 'boolean',
            'edit_ratio'                  => 'decimal:4',
            'pushed_at'                   => 'datetime',
            'discarded_at'                => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ArticleDraftHistory::class);
    }

    public function pushOperations(): HasMany
    {
        return $this->hasMany(WordPressPushOperation::class);
    }

    /**
     * 対象の記事（新規記事の場合はNULL）
     */
    public function article(): Post|Page|null
    {
        return $this->post ?? $this->page;
    }

    public function isNewArticle(): bool
    {
        return $this->post_id === null && $this->page_id === null;
    }

    /**
     * 結果が確定していない反映記録があり、再反映できない（WORDPRESS_API 24-1）
     */
    public function isLocked(): bool
    {
        return $this->pushOperations()->whereIn('state', PushState::locking())->exists();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('state', DraftState::active());
    }
}
