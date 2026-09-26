<?php

namespace App\Repositories;

use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Page;
use App\Models\Post;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * BlogOSのAI機能の実行記録（ai_generations）。BLOGOS_DATABASE.md 9-6。
 */
class AiGenerationRepository
{
    public function create(array $attributes): AiGeneration
    {
        return AiGeneration::create($attributes);
    }

    public function update(AiGeneration $generation, array $attributes): void
    {
        $generation->update($attributes);
    }

    public function find(int $id): ?AiGeneration
    {
        return AiGeneration::find($id);
    }

    /**
     * API実行を始める印を付ける。すでに始まっている（同じJobが2回動いた）場合は false（二重の課金を防ぐ）
     */
    public function claimForApiRun(AiGeneration $generation): bool
    {
        return AiGeneration::whereKey($generation->id)
            ->where('execution_method', AiExecutionMethod::Api)
            ->where('status', AiGenerationStatus::Running)
            ->whereNull('started_at')
            ->update(['started_at' => now()]) === 1;
    }

    /**
     * 指定した日時以降のAPI実行の費用の目安の合計（米ドル）。失敗した実行も、課金された分を含む
     */
    public function apiCostSince(DateTimeInterface $from): float
    {
        return (float) AiGeneration::where('execution_method', AiExecutionMethod::Api)
            ->where('created_at', '>=', $from)
            ->sum('estimated_cost');
    }

    public function findForBlog(int $blogId, int $id): ?AiGeneration
    {
        return AiGeneration::with(['post', 'page', 'draft', 'requester', 'createdDrafts', 'evaluations'])->where('blog_id', $blogId)->find($id);
    }

    /**
     * @return Collection<int, AiGeneration>
     */
    public function listForBlog(int $blogId, int $limit = 200): Collection
    {
        return AiGeneration::with(['post:id,title_raw', 'page:id,title_raw', 'draft:id,title_raw', 'requester:id,name'])
            ->where('blog_id', $blogId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'blog_id', 'post_id', 'page_id', 'article_draft_id', 'purpose', 'revision_scope', 'execution_method', 'service_plan', 'model', 'status', 'requested_by', 'created_at', 'completed_at']);
    }

    /**
     * @return Collection<int, AiGeneration>
     */
    public function forArticle(Post|Page $article, int $limit = 30): Collection
    {
        return AiGeneration::where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'purpose', 'execution_method', 'model', 'status', 'created_at']);
    }

    /**
     * @return Collection<int, AiGeneration>
     */
    public function forDraft(ArticleDraft $draft, int $limit = 30): Collection
    {
        return AiGeneration::where('article_draft_id', $draft->id)
            ->orWhereKey($draft->ai_generation_id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'purpose', 'execution_method', 'model', 'status', 'created_at']);
    }
}
