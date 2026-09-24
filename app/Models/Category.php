<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'blog_id',
        'category_id',
        'name',
        'slug',
        'parent_id',
        'link',
        'description',
        'taxonomy',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CategoryHistory::class);
    }
}
