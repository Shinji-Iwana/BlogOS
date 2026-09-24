<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends Model
{
    protected $fillable = [
        'blog_id',
        'status_id',
        'slug',
        'name',
        'private',
        'protected',
        'public',
        'queryable',
        'show_in_list',
        'date_floating',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(StatusHistory::class);
    }
}
