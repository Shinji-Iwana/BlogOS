<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'blog_id',
        'page_id',
        'date',
        'date_gmt',
        'modified',
        'modified_gmt',
        'guid',
        'link',
        'slug',
        'status_id',
        'type_id',
        'author_id',
        'password',
        'title',
        'content',
        'excerpt',
        'featured_media_id',
        'comment_status',
        'ping_status',
        'template',
        'parent_id',
        'menu_order',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PageHistory::class);
    }
}
