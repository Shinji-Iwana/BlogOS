<?php

namespace App\Repositories;

use App\Enums\ArticleMaterialSource;
use App\Enums\MaterialStatus;
use App\Enums\SuggestionStatus;
use App\Models\ArticleMaterial;
use App\Models\ArticleMaterialReview;
use App\Models\Material;
use App\Models\MaterialSuggestion;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 教材（materials）・記事で使っている教材（article_materials）・教材の案（material_suggestions）・見直しの結果。D-30。
 */
class MaterialRepository
{
    /**
     * @return Collection<int, Material>
     */
    public function listForBlog(int $blogId): Collection
    {
        return Material::with(['categories:id,name', 'previous:id,name', 'successors:id,name,previous_material_id,created_at'])
            ->withCount('articleMaterials')
            ->where('blog_id', $blogId)
            ->orderBy('kind')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Material>
     */
    public function activeForBlog(int $blogId): Collection
    {
        return Material::with('categories:id,parent_id,name')->where('blog_id', $blogId)->where('status', MaterialStatus::Active)->get();
    }

    /**
     * @return Collection<int, Material>
     */
    public function allForBlog(int $blogId): Collection
    {
        return Material::where('blog_id', $blogId)->get();
    }

    public function findForBlog(int $blogId, int $id): ?Material
    {
        return Material::with(['categories', 'previous', 'successors'])->where('blog_id', $blogId)->find($id);
    }

    public function create(array $attributes, array $categoryIds): Material
    {
        return DB::transaction(function () use ($attributes, $categoryIds) {
            $material = Material::create($attributes);
            $material->categories()->sync($categoryIds);

            return $material;
        });
    }

    public function update(Material $material, array $attributes, ?array $categoryIds = null): void
    {
        DB::transaction(function () use ($material, $attributes, $categoryIds) {
            $material->update($attributes);
            if ($categoryIds !== null) {
                $material->categories()->sync($categoryIds);
            }
        });
    }

    /**
     * 定期チェックの対象（前回の調査からの期間が過ぎた、使う教材。調査したことがない教材を先にする）
     *
     * @return Collection<int, Material>
     */
    public function dueForCheck(int $blogId, \DateTimeInterface $researchedBefore, int $limit): Collection
    {
        return Material::where('blog_id', $blogId)
            ->where('status', MaterialStatus::Active)
            ->where(fn ($query) => $query->whereNull('researched_at')->orWhere('researched_at', '<', $researchedBefore))
            ->orderByRaw('researched_at IS NOT NULL')
            ->orderBy('researched_at')
            ->limit($limit)
            ->get();
    }

    // ---- 記事で使っている教材 ----

    /**
     * @return Collection<int, ArticleMaterial>
     */
    public function forArticle(Post|Page $article): Collection
    {
        // 見直しの判定（reviewReason）で記事の更新日時を使うため、記事も読み込む
        return ArticleMaterial::with(['material.successors', 'material.categories:id,name', 'post:id,title_raw,link,wordpress_modified_gmt,status', 'page:id,title_raw,link,wordpress_modified_gmt,status'])
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->get();
    }

    /**
     * @return Collection<int, ArticleMaterial>
     */
    public function articleMaterialsForBlog(int $blogId): Collection
    {
        return ArticleMaterial::with(['material.successors', 'post:id,title_raw,link,wordpress_modified_gmt,status', 'page:id,title_raw,link,wordpress_modified_gmt,status'])
            ->where('blog_id', $blogId)
            ->get();
    }

    /**
     * @return Collection<int, ArticleMaterial>
     */
    public function articlesUsing(Material $material): Collection
    {
        return ArticleMaterial::with(['post:id,title_raw,link,wordpress_modified_gmt,status', 'page:id,title_raw,link,wordpress_modified_gmt,status', 'material.successors'])
            ->where('material_id', $material->id)
            ->get();
    }

    /**
     * 本文から検出した教材を記録し直す。本文からなくなった教材の記録は消す（人が登録した記録は残す）。
     * 新しく記録した教材は、記録した時点で見直したものとする（今の本文のまま）
     *
     * @param array<int, int> $materialIds 本文にある教材
     */
    public function replaceDetected(Post|Page $article, array $materialIds): void
    {
        $column = ArticleContentRepository::articleColumn($article);

        DB::transaction(function () use ($article, $column, $materialIds) {
            ArticleMaterial::where($column, $article->id)
                ->where('source', ArticleMaterialSource::Detected)
                ->whereNotIn('material_id', $materialIds ?: [0])
                ->delete();

            $existing = ArticleMaterial::where($column, $article->id)->pluck('material_id')->all();
            foreach (array_diff(array_unique($materialIds), $existing) as $materialId) {
                ArticleMaterial::create([
                    'blog_id'     => $article->blog_id,
                    $column       => $article->id,
                    'material_id' => $materialId,
                    'source'      => ArticleMaterialSource::Detected,
                    'reviewed_at' => now(),
                ]);
            }
        });
    }

    /**
     * 記事の教材を見直したことにする
     */
    public function markArticleReviewed(Post|Page $article): void
    {
        ArticleMaterial::where(ArticleContentRepository::articleColumn($article), $article->id)->update(['reviewed_at' => now()]);
    }

    // ---- 教材の案 ----

    public function createSuggestion(array $attributes): MaterialSuggestion
    {
        return DB::transaction(function () use ($attributes) {
            // 同じ教材の確認待ちの情報の案は、新しい案に置き換える
            if (($attributes['material_id'] ?? null) !== null) {
                MaterialSuggestion::where('material_id', $attributes['material_id'])
                    ->where('type', $attributes['type'])
                    ->where('status', SuggestionStatus::Pending)
                    ->update(['status' => SuggestionStatus::Superseded]);
            }

            return MaterialSuggestion::create($attributes + ['status' => SuggestionStatus::Pending]);
        });
    }

    /**
     * @return Collection<int, MaterialSuggestion>
     */
    public function pendingSuggestions(int $blogId): Collection
    {
        return MaterialSuggestion::with(['material.categories:id,name', 'relatedMaterial:id,name', 'generation:id,use_web_search'])
            ->where('blog_id', $blogId)
            ->where('status', SuggestionStatus::Pending)
            ->orderBy('type')
            ->orderBy('id')
            ->get();
    }

    public function findPendingSuggestion(int $blogId, int $id): ?MaterialSuggestion
    {
        return MaterialSuggestion::with(['material', 'relatedMaterial:id,name', 'generation:id'])->where('blog_id', $blogId)->where('status', SuggestionStatus::Pending)->find($id);
    }

    public function countPendingSuggestions(int $blogId): int
    {
        return MaterialSuggestion::where('blog_id', $blogId)->where('status', SuggestionStatus::Pending)->count();
    }

    public function markSuggestionReviewed(MaterialSuggestion $suggestion, SuggestionStatus $status, ?int $userId, ?int $materialId = null): void
    {
        $suggestion->update(array_filter([
            'status'      => $status,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'material_id' => $materialId,
        ], fn ($value) => $value !== null));
    }

    // ---- 見直しの結果 ----

    public function createReview(Post|Page $article, array $attributes): ArticleMaterialReview
    {
        return DB::transaction(function () use ($article, $attributes) {
            $column = ArticleContentRepository::articleColumn($article);

            ArticleMaterialReview::where($column, $article->id)->where('status', SuggestionStatus::Pending)
                ->update(['status' => SuggestionStatus::Superseded]);

            return ArticleMaterialReview::create($attributes + [
                'blog_id' => $article->blog_id,
                $column   => $article->id,
                'status'  => SuggestionStatus::Pending,
            ]);
        });
    }

    /**
     * @return Collection<int, ArticleMaterialReview>
     */
    public function pendingReviews(int $blogId): Collection
    {
        return ArticleMaterialReview::with(['post:id,title_raw,link', 'page:id,title_raw,link'])
            ->where('blog_id', $blogId)
            ->where('status', SuggestionStatus::Pending)
            ->orderBy('id')
            ->get();
    }

    public function latestReviewFor(Post|Page $article): ?ArticleMaterialReview
    {
        return ArticleMaterialReview::where(ArticleContentRepository::articleColumn($article), $article->id)
            ->whereIn('status', [SuggestionStatus::Pending, SuggestionStatus::Accepted])
            ->latest('id')
            ->first();
    }

    public function findPendingReview(int $blogId, int $id): ?ArticleMaterialReview
    {
        return ArticleMaterialReview::with(['post', 'page'])->where('blog_id', $blogId)->where('status', SuggestionStatus::Pending)->find($id);
    }

    public function markReviewConfirmed(ArticleMaterialReview $review, SuggestionStatus $status, ?int $userId): void
    {
        $review->update(['status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
    }

    /**
     * 確認待ちの見直しの結果がある記事
     *
     * @return array{posts: array<int, true>, pages: array<int, true>}
     */
    public function pendingReviewArticles(int $blogId): array
    {
        $rows = ArticleMaterialReview::where('blog_id', $blogId)->where('status', SuggestionStatus::Pending)->get(['post_id', 'page_id']);

        return [
            'posts' => array_fill_keys($rows->whereNotNull('post_id')->pluck('post_id')->all(), true),
            'pages' => array_fill_keys($rows->whereNotNull('page_id')->pluck('page_id')->all(), true),
        ];
    }
}
