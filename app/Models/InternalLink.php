<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 本文から抽出した、同じブログ内へのリンク（BLOGOS_DATABASE.md 7-1）。
 */
class InternalLink extends Model
{
    protected $guarded = ['id'];

    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function targetPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'target_post_id');
    }

    public function targetPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'target_page_id');
    }
}
