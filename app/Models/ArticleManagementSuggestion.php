<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AIが作った記事の管理情報（記事種類・キーワード・検索意図）の案。人が確認して登録する（D-27）。
 */
class ArticleManagementSuggestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sub_keywords'       => 'array',
            'sub_search_intents' => 'array',
            'status'             => SuggestionStatus::class,
            'reviewed_at'        => 'datetime',
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
