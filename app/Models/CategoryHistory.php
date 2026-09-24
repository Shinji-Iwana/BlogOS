<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'blog_id',
        'category_id',
        'field',
        'old_value',
        'new_value',
        'source',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
