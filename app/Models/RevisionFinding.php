<?php

namespace App\Models;

use App\Enums\Judgment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 記事改修で直すべき指摘と、その対応・改修後の確認（D-47 S3）。
 */
class RevisionFinding extends Model
{
    protected $guarded = ['id'];

    public const RESPONSES = ['fixed' => '直した', 'partial' => '一部直した', 'not_fixed' => '直さなかった'];

    public const CHECKS = ['resolved' => '解消', 'partial' => '一部解消', 'unresolved' => '未解消'];

    protected function casts(): array
    {
        return [
            'judgment' => Judgment::class,
        ];
    }

    public function sourceEvaluation(): BelongsTo
    {
        return $this->belongsTo(ArticleEvaluation::class, 'source_evaluation_id');
    }

    public function checkEvaluation(): BelongsTo
    {
        return $this->belongsTo(ArticleEvaluation::class, 'check_evaluation_id');
    }
}
