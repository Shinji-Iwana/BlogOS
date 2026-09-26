<?php

namespace App\Models;

use App\Enums\AiBatchItemStatus;
use App\Enums\ReevaluationReason;
use App\Enums\RevisionScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * まとめて実行の対象の記事（D-25）。
 */
class AiBatchItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reason'         => ReevaluationReason::class,
            'status'         => AiBatchItemStatus::class,
            'revision_scope' => RevisionScope::class,
            'score_before'   => 'float',
            'score_after'    => 'float',
        ];
    }

    /**
     * 改修の後に、できた編集案を品質診断したAI実行記録（D-27-01）
     */
    public function diagnosisGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'diagnosis_generation_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiBatch::class, 'ai_batch_id');
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
