<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'blog_id',
        'field',
        'old_value',
        'new_value',
        'source',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }
}
