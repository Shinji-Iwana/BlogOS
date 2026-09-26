<?php

namespace App\Repositories;

use App\Enums\SuggestionStatus;
use App\Models\ArticleManagement;
use App\Models\ArticleManagementSuggestion;
use App\Models\ArticleKeyword;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AIが作った記事の管理情報の案（article_management_suggestions）。D-27。
 */
class ArticleManagementSuggestionRepository
{
    /**
     * 案を保存する。同じ記事の確認待ちの案は「新しい案に置き換え」にする
     */
    public function create(Post|Page $article, array $attributes): ArticleManagementSuggestion
    {
        return DB::transaction(function () use ($article, $attributes) {
            $column = ArticleContentRepository::articleColumn($article);

            ArticleManagementSuggestion::where($column, $article->id)->where('status', SuggestionStatus::Pending)
                ->update(['status' => SuggestionStatus::Superseded]);

            return ArticleManagementSuggestion::create($attributes + [
                'blog_id' => $article->blog_id,
                $column   => $article->id,
                'status'  => SuggestionStatus::Pending,
            ]);
        });
    }

    /**
     * @return Collection<int, ArticleManagementSuggestion>
     */
    public function pendingForBlog(int $blogId, int $limit = 300): Collection
    {
        return ArticleManagementSuggestion::with(['post:id,title_raw,link', 'page:id,title_raw,link'])
            ->where('blog_id', $blogId)
            ->where('status', SuggestionStatus::Pending)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function countPending(int $blogId): int
    {
        return ArticleManagementSuggestion::where('blog_id', $blogId)->where('status', SuggestionStatus::Pending)->count();
    }

    /**
     * @param array<int, int> $ids
     * @return Collection<int, ArticleManagementSuggestion>
     */
    public function pendingByIds(int $blogId, array $ids): Collection
    {
        return ArticleManagementSuggestion::with(['post', 'page'])
            ->where('blog_id', $blogId)
            ->where('status', SuggestionStatus::Pending)
            ->whereIn('id', $ids)
            ->get();
    }

    public function markReviewed(ArticleManagementSuggestion $suggestion, SuggestionStatus $status, ?int $userId): void
    {
        $suggestion->update(['status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
    }

    /**
     * 確認待ちの案がある記事
     *
     * @return array{posts: array<int, true>, pages: array<int, true>}
     */
    public function pendingArticles(int $blogId): array
    {
        $rows = ArticleManagementSuggestion::where('blog_id', $blogId)->where('status', SuggestionStatus::Pending)->get(['post_id', 'page_id']);

        return [
            'posts' => $rows->whereNotNull('post_id')->pluck('post_id')->flip()->map(fn () => true)->all(),
            'pages' => $rows->whereNotNull('page_id')->pluck('page_id')->flip()->map(fn () => true)->all(),
        ];
    }

    /**
     * 管理情報を登録済みの記事（記事種類とメインキーワードの両方がある）
     *
     * @return array{posts: array<int, true>, pages: array<int, true>}
     */
    public function managedArticles(int $blogId): array
    {
        $result = ['posts' => [], 'pages' => []];

        foreach (['post_id' => 'posts', 'page_id' => 'pages'] as $column => $key) {
            $typed = ArticleManagement::where('blog_id', $blogId)->whereNotNull($column)->whereNotNull('article_type')->pluck($column)->all();
            $keyworded = ArticleKeyword::where('blog_id', $blogId)->whereNotNull($column)->where('keyword_type', 'main')->pluck($column)->all();
            $result[$key] = array_fill_keys(array_intersect($typed, $keyworded), true);
        }

        return $result;
    }
}
