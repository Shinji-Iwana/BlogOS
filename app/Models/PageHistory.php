<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'blog_id',
        'page_id',
        'field',
        'old_value',
        'new_value',
        'source',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
