<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 記事の企画の案（D-40）。記事の案（type=article）と、子カテゴリの案（type=category）。
 */
class TopicSuggestion extends Model
{
    protected $guarded = ['id'];

    public const PRIORITIES = ['high' => '高', 'medium' => '中', 'low' => '低'];

    protected function casts(): array
    {
        return [
            'status'       => SuggestionStatus::class,
            'sub_keywords' => 'array',
            'sources'      => 'array',
            'reviewed_at'  => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TopicSuggestion::class, 'parent_suggestion_id');
    }

    /**
     * 子カテゴリの案の、最初の記事の案
     */
    public function articles(): HasMany
    {
        return $this->hasMany(TopicSuggestion::class, 'parent_suggestion_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }

    /**
     * 立ち上げる子カテゴリ（D-41）
     */
    public function launchChild(): BelongsTo
    {
        return $this->belongsTo(CategoryLaunchChild::class, 'launch_child_id');
    }

    /**
     * この案から作った記事の編集案と、作成中の AI 実行（D-41）
     */
    public function articleDraft(): BelongsTo
    {
        return $this->belongsTo(ArticleDraft::class, 'article_draft_id');
    }

    public function articleGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'article_generation_id');
    }
}
