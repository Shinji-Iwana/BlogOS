<?php

namespace App\Models;

use App\Enums\KeywordType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 記事のキーワード（BLOGOS_DATABASE.md 9-3）。
 */
class ArticleKeyword extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'keyword_type' => KeywordType::class,
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
