<?php

namespace App\Repositories;

use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
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

    /**
     * 指定した日時より後に費用が記録されたAPI実行の、費用の目安の合計（残高の見込みの計算。D-31-04）。
     * 実行の途中（まだ費用がない）ものは、終わったときに加わる
     */
    public function apiCostAfter(DateTimeInterface $after): float
    {
        return (float) AiGeneration::where('execution_method', AiExecutionMethod::Api)
            ->whereRaw('COALESCE(completed_at, created_at) > ?', [$after])
            ->sum('estimated_cost');
    }

    /**
     * API実行の日ごと・モデルごとの集計（OpenAI の Usage の画面と比べるため。日付は UTC。D-31-04）
     *
     * @return Collection<int, object{day: string, model: string, requests: int, input_tokens: int, cached_input_tokens: int, output_tokens: int, web_search_calls: int, cost: float}>
     */
    public function apiUsageByDay(DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        return AiGeneration::where('execution_method', AiExecutionMethod::Api)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('input_tokens')
            ->selectRaw('DATE(created_at) as day, model, COUNT(*) as requests, SUM(input_tokens) as input_tokens, SUM(COALESCE(cached_input_tokens, 0)) as cached_input_tokens, SUM(output_tokens) as output_tokens, SUM(COALESCE(web_search_calls, 0)) as web_search_calls, SUM(estimated_cost) as cost')
            ->groupBy('day', 'model')
            ->orderByDesc('day')
            ->orderBy('model')
            ->toBase()
            ->get();
    }

    /**
     * 成功したAPI実行の、1回あたりの費用の平均（まとめて実行の費用の目安。モデル名は応答の日付付きの名前を含む）
     */
    public function averageApiCost(AiMode $mode, string $model): ?float
    {
        $average = AiGeneration::where('execution_method', AiExecutionMethod::Api)
            ->where('purpose', $mode)
            ->where('status', AiGenerationStatus::Succeeded)
            ->where(fn ($query) => $query->where('model', $model)->orWhere('model', 'like', "{$model}-%"))
            ->avg('estimated_cost');

        return $average !== null ? (float) $average : null;
    }

    public function findForBlog(int $blogId, int $id): ?AiGeneration
    {
        return AiGeneration::with(['post', 'page', 'draft', 'material', 'requester', 'createdDrafts', 'evaluations'])->where('blog_id', $blogId)->find($id);
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
