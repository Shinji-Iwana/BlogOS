<?php

namespace App\Models;

use App\Enums\EvaluatorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 記事の品質評価（BLOGOS_DATABASE.md 9-5、D-06-02、D-06-09、D-07-03）。
 *
 * 1つの記事を何回でも評価できる。公開の可否は、人が確定した評価（is_confirmed）で判断する。
 */
class ArticleEvaluation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'is_confirmed' => false,
    ];

    protected function casts(): array
    {
        return [
            'evaluator_type'                   => EvaluatorType::class,
            'evaluated_wordpress_modified_gmt' => 'datetime',
            'required_conditions_passed'       => 'boolean',
            'score'                            => 'float',
            'is_confirmed'                     => 'boolean',
            'confirmed_at'                     => 'datetime',
        ];
    }

    public function details(): HasMany
    {
        return $this->hasMany(ArticleEvaluationDetail::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ArticleDraft::class, 'article_draft_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
