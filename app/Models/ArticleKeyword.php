<?php

namespace App\Models;

use App\Enums\KeywordType;
use Illuminate\Database\Eloquent\Model;

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
}
