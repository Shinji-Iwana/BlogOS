<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 収益記事へのリンクを、集客記事に入れた記録（D-93-02）。
 */
class RevenueArticleLink extends Model
{
    public const ADDED = 'added';

    public const LINKED = 'linked';

    public const NO_BOX = 'no_box';

    protected $guarded = ['id'];

    public function revenuePost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'revenue_post_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ArticleDraft::class, 'article_draft_id');
    }
}
