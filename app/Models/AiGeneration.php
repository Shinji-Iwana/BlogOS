<?php

namespace App\Models;

use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\DraftState;
use App\Enums\RevisionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BlogOSのAI機能の実行記録（BLOGOS_DATABASE.md 9-6、D-07-04、D-07-07）。
 */
class AiGeneration extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose'          => AiMode::class,
            'revision_scope'   => RevisionScope::class,
            'parameters'       => 'array',
            'execution_method' => AiExecutionMethod::class,
            'status'           => AiGenerationStatus::class,
            'estimated_cost'   => 'float',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * この実行から作った編集案
     */
    public function createdDrafts(): HasMany
    {
        return $this->hasMany(ArticleDraft::class, 'ai_generation_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ArticleEvaluation::class, 'ai_generation_id');
    }

    /**
     * 採用されなかった記録は1年で削除する。採用された記録（反映された編集案にひも付くもの）は残す（D-07-04）
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subYear())
            ->whereDoesntHave('createdDrafts', fn ($query) => $query->where('state', DraftState::Pushed));
    }
}
