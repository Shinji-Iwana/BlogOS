<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Taxonomy extends Model
{
    protected $fillable = [
        'blog_id',
        'taxonomy_id',
        'slug',
        'name',
        'description',
        'hierarchical',
        'rest_base',
        'rest_namespace',
        'types',
        'visibility',
        'labels',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TaxonomyHistory::class);
    }
}
