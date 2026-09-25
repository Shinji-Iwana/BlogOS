<?php

namespace App\Models;

use App\Enums\RelationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 人が登録する、設計上の記事同士の関係（BLOGOS_DATABASE.md 9-4、D-08-04）。
 */
class ArticleRelation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'relation_type' => RelationType::class,
        ];
    }

    public function relatedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'related_post_id');
    }

    public function relatedPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'related_page_id');
    }
}
