<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AIによる、記事の教材の見直しの結果（D-30）。人が確認する。
 */
class ArticleMaterialReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'result'      => 'array',
            'status'      => SuggestionStatus::class,
            'reviewed_at' => 'datetime',
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

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }

    public function article(): Post|Page|null
    {
        return $this->post ?? $this->page;
    }
}
