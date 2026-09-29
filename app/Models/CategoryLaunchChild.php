<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 立ち上げる子カテゴリ（D-41）。既存の子カテゴリ（category_id）か、採用した子カテゴリの案（topic_suggestion_id。WordPress にはまだない）。
 */
class CategoryLaunchChild extends Model
{
    protected $guarded = ['id'];

    public function launch(): BelongsTo
    {
        return $this->belongsTo(CategoryLaunch::class, 'category_launch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(TopicSuggestion::class, 'topic_suggestion_id');
    }

    public function roadmapDraft(): BelongsTo
    {
        return $this->belongsTo(ArticleDraft::class, 'roadmap_draft_id');
    }

    /**
     * この子カテゴリの記事の案
     */
    public function articleSuggestions(): HasMany
    {
        return $this->hasMany(TopicSuggestion::class, 'launch_child_id');
    }

    /**
     * WordPress にまだないカテゴリか
     */
    public function isNew(): bool
    {
        return $this->category_id === null;
    }
}
