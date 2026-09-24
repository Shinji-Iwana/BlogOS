<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Type extends Model
{
    protected $fillable = [
        'blog_id',
        'type_id',
        'slug',
        'name',
        'description',
        'hierarchical',
        'viewable',
        'has_archive',
        'rest_base',
        'rest_namespace',
        'icon',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TypeHistory::class);
    }
}
