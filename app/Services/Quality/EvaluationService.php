<?php

namespace App\Services\Quality;

use App\Enums\EvaluatorType;
use App\Enums\Judgment;
use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleManagementRepository;

/**
 * 記事の品質評価の保存と、人による確定（D-06-02、D-06-09、D-07-03）。
 *
 * 点数は品質基準（resources/quality）から計算し、評価に使った記事種類と品質基準のバージョンを記録する。
 */
class EvaluationService
{
    public function __construct(
        protected QualityStandardLoader $loader,
        protected ScoreCalculator $calculator,
        protected ArticleEvaluationRepository $evaluations,
        protected ArticleManagementRepository $managements,
    ) {
    }

    public function standardFor(Blog $blog): QualityStandard
    {
        return $this->loader->load($blog->quality_profile);
    }

    /**
     * 評価の対象の記事種類（記事の管理情報。新規記事の編集案では、指定された値）
     */
    public function articleTypeFor(Post|Page|null $article, ?string $fallback = null): ?string
    {
        return $article !== null ? ($this->managements->findFor($article)?->article_type ?? $fallback) : $fallback;
    }

    /**
     * 評価を保存する。
     *
     * AIの評価では、判定者が「人」の項目を必ず「要人間確認」にし、AIの点数に含めない（D-07-03）。
     * 人の評価で確定する場合は、全ての項目と必須条件を ○・△・× で判定していなければならない。
     *
     * @param Post|Page|ArticleDraft $target 評価した記事、または編集案
     * @param array<string, Judgment> $judgments
     * @param array<string, string|null> $comments
     *
     * @throws EvaluationException 確定できない場合
     */
    public function save(
        Blog $blog,
        Post|Page|ArticleDraft $target,
        EvaluatorType $evaluator,
        array $judgments,
        array $comments,
        ?string $summary,
        ?int $aiGenerationId,
        ?int $userId,
        bool $confirm = false,
        ?string $articleType = null,
    ): ArticleEvaluation {
        $standard = $this->standardFor($blog);

        $draft = $target instanceof ArticleDraft ? $target : null;
        $article = $draft ? $draft->article() : $target;
        $articleType ??= $this->articleTypeFor($article);

        if ($evaluator === EvaluatorType::Ai) {
            foreach ($standard->items as $key => $item) {
                if (! $item['ai']) {
                    $judgments[$key] = Judgment::NeedsHuman;
                }
            }
            foreach ($standard->required as $key => $condition) {
                if (! $condition['ai'] && ($judgments[$key] ?? null) !== Judgment::Bad) {
                    // AIは「指摘のみ」。問題を指摘した（×）場合は残す
                    $judgments[$key] = Judgment::NeedsHuman;
                }
            }
        }

        $known = array_merge(array_keys($standard->items), array_keys($standard->required));
        $judgments = array_intersect_key($judgments, array_flip($known));

        $result = $this->calculator->calculate($standard, $articleType, $judgments);

        if ($confirm && ($evaluator !== EvaluatorType::Human || $result['unjudged'] !== [] || $result['needs_human'] !== [])) {
            throw new EvaluationException('人が全ての項目と必須条件を ○・△・× で判定した評価だけを確定できます。');
        }

        $details = [];
        $applicable = $standard->applicableItems($articleType);
        foreach ($judgments as $key => $judgment) {
            $item = $applicable[$key] ?? null;
            if ($item === null && ! isset($standard->required[$key])) {
                continue; // 対象外の項目
            }

            $details[] = [
                'item_key'   => $key,
                'judgment'   => $judgment,
                'points'     => $item === null ? null : match ($judgment) {
                    Judgment::Good       => (float) $item['points'],
                    Judgment::Partial    => $item['points'] / 2,
                    Judgment::Bad        => 0.0,
                    Judgment::NeedsHuman => null,
                },
                'max_points' => $item['points'] ?? null,
                'comment'    => filled($comments[$key] ?? null) ? mb_substr((string) $comments[$key], 0, 5000) : null,
            ];
        }

        return $this->evaluations->create([
            'blog_id'                          => $blog->id,
            'post_id'                          => $article instanceof Post ? $article->id : null,
            'page_id'                          => $article instanceof Page ? $article->id : null,
            'article_draft_id'                 => $draft?->id,
            'evaluated_wordpress_modified_gmt' => $draft === null ? $article?->wordpress_modified_gmt : null,
            'evaluator_type'                   => $evaluator,
            'ai_generation_id'                 => $aiGenerationId,
            'article_type'                     => $articleType,
            'quality_common_version'           => $standard->commonVersion,
            'quality_profile'                  => $standard->profile,
            'quality_profile_version'          => $standard->profileVersion,
            'required_conditions_passed'       => $result['required_passed'],
            'score'                            => $result['score'],
            'summary'                          => $summary,
            'is_confirmed'                     => $confirm,
            'confirmed_by'                     => $confirm ? $userId : null,
            'confirmed_at'                     => $confirm ? now() : null,
            'created_by'                       => $userId,
        ], $details);
    }
}
