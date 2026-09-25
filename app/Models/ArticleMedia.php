<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 本文中で使われているメディア（BLOGOS_DATABASE.md 7-2）。
 */
class ArticleMedia extends Model
{
    protected $table = 'article_media';

    protected $guarded = ['id'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
