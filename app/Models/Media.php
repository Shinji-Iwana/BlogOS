<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Media extends Model
{
    protected $fillable = [
        'blog_id',
        'media_id',
        'date',
        'date_gmt',
        'modified',
        'modified_gmt',
        'slug',
        'status',
        'type',
        'author_id',
        'parent_id',
        'title',
        'caption',
        'description',
        'alt_text',
        'media_type',
        'mime_type',
        'source_url',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MediaHistory::class);
    }
}
