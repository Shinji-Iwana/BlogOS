<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $fillable = [
        'blog_id',
        'author_id',
        'username',
        'name',
        'first_name',
        'last_name',
        'email',
        'url',
        'description',
        'link',
        'locale',
        'nickname',
        'slug',
        'registered_date',
        'roles',
        'avatar_urls',
        'last_synced_at',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AuthorHistory::class);
    }
}
