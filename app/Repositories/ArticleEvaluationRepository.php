<?php

namespace App\Repositories;

use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 記事の品質評価（article_evaluations・article_evaluation_details）。BLOGOS_DATABASE.md 9-5。
 */
class ArticleEvaluationRepository
{
    /**
     * @param array<int, array{item_key: string, judgment: \App\Enums\Judgment, points: float|null, max_points: int|null, comment: string|null}> $details
     */
    public function create(array $attributes, array $details): ArticleEvaluation
    {
        return DB::transaction(function () use ($attributes, $details) {
            $evaluation = ArticleEvaluation::create($attributes);
            $evaluation->details()->createMany($details);

            return $evaluation;
        });
    }

    public function confirm(ArticleEvaluation $evaluation, ?int $userId): void
    {
        $evaluation->update(['is_confirmed' => true, 'confirmed_by' => $userId, 'confirmed_at' => now()]);
    }

    public function findForBlog(int $blogId, int $id): ?ArticleEvaluation
    {
        return ArticleEvaluation::with(['details', 'post', 'page', 'draft', 'generation', 'creator', 'confirmer'])
            ->where('blog_id', $blogId)
            ->find($id);
    }

    /**
     * @return Collection<int, ArticleEvaluation>
     */
    public function forArticle(Post|Page $article, int $limit = 50): Collection
    {
        return ArticleEvaluation::with('creator')
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, ArticleEvaluation>
     */
    public function forDraft(ArticleDraft $draft, int $limit = 50): Collection
    {
        return ArticleEvaluation::with('creator')
            ->where('article_draft_id', $draft->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 記事または編集案の最新の評価（AIの評価の不足点を、改修の指示に使う）
     */
    public function latestFor(Post|Page|null $article, ?ArticleDraft $draft): ?ArticleEvaluation
    {
        $query = ArticleEvaluation::with('details')->orderByDesc('id');

        if ($draft !== null) {
            $query->where('article_draft_id', $draft->id);
        } elseif ($article !== null) {
            $query->where(ArticleContentRepository::articleColumn($article), $article->id)->whereNull('article_draft_id');
        } else {
            return null;
        }

        return $query->first();
    }
}
