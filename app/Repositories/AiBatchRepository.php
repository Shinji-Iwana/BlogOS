<?php

namespace App\Repositories;

use App\Enums\AiBatchItemStatus;
use App\Enums\AiBatchStatus;
use App\Enums\AiBatchTrigger;
use App\Models\AiBatch;
use App\Models\AiBatchItem;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BlogOSのAI機能のまとめて実行（ai_batches・ai_batch_items）。D-25。
 */
class AiBatchRepository
{
    /**
     * @param array<int, array{post_id: int|null, page_id: int|null, reason: string|null}> $items
     */
    public function create(array $attributes, array $items): AiBatch
    {
        return DB::transaction(function () use ($attributes, $items) {
            $batch = AiBatch::create($attributes + ['total_count' => count($items)]);
            foreach ($items as $item) {
                $batch->items()->create($item + ['status' => AiBatchItemStatus::Pending]);
            }

            return $batch;
        });
    }

    public function find(int $id): ?AiBatch
    {
        return AiBatch::find($id);
    }

    public function findItem(int $id): ?AiBatchItem
    {
        return AiBatchItem::with(['batch.blog', 'post', 'page'])->find($id);
    }

    public function findForBlog(int $blogId, int $id): ?AiBatch
    {
        return AiBatch::with(['requester:id,name', 'parent:id,purpose', 'children:id,parent_batch_id,purpose,status,total_count'])->where('blog_id', $blogId)->find($id);
    }

    /**
     * @return Collection<int, AiBatchItem>
     */
    public function items(AiBatch $batch): Collection
    {
        return AiBatchItem::with(['post:id,title_raw', 'page:id,title_raw', 'generation:id,status,estimated_cost', 'generation.evaluations:id,ai_generation_id,score', 'generation.createdDrafts:id,ai_generation_id', 'diagnosisGeneration:id,estimated_cost'])
            ->where('ai_batch_id', $batch->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, AiBatch>
     */
    public function listForBlog(int $blogId, int $limit = 100): Collection
    {
        return AiBatch::with('requester:id,name')->where('blog_id', $blogId)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * 状態ごとの記事の数と、費用の目安の合計
     *
     * @return array{counts: array<string, int>, cost: float}
     */
    public function progress(AiBatch $batch): array
    {
        $counts = AiBatchItem::where('ai_batch_id', $batch->id)->groupBy('status')->selectRaw('status, COUNT(*) AS item_count')
            ->pluck('item_count', 'status')->map(fn ($value) => (int) $value)->all();

        // 記事ごとの実行と、改修の後の編集案の品質診断（D-27-01）の費用
        $cost = 0.0;
        foreach (['ai_generation_id', 'diagnosis_generation_id'] as $column) {
            $cost += (float) DB::table('ai_batch_items')->join('ai_generations', 'ai_generations.id', '=', "ai_batch_items.{$column}")
                ->where('ai_batch_items.ai_batch_id', $batch->id)->sum('ai_generations.estimated_cost');
        }

        return ['counts' => $counts, 'cost' => $cost];
    }

    /**
     * 待機中・実行中の記事（同じ記事を重ねて実行しないため）
     *
     * @return array{posts: array<int, true>, pages: array<int, true>}
     */
    public function activeArticles(int $blogId): array
    {
        $rows = AiBatchItem::whereIn('status', [AiBatchItemStatus::Pending, AiBatchItemStatus::Running])
            ->whereHas('batch', fn ($query) => $query->where('blog_id', $blogId))
            ->get(['post_id', 'page_id']);

        return [
            'posts' => $rows->whereNotNull('post_id')->pluck('post_id')->flip()->map(fn () => true)->all(),
            'pages' => $rows->whereNotNull('page_id')->pluck('page_id')->flip()->map(fn () => true)->all(),
        ];
    }

    /**
     * 待機中の記事を実行中にする。取り消し・二重の実行の場合は false
     */
    public function claimItem(AiBatchItem $item): bool
    {
        return AiBatchItem::whereKey($item->id)->where('status', AiBatchItemStatus::Pending)
            ->whereHas('batch', fn ($query) => $query->where('status', AiBatchStatus::Running))
            ->update(['status' => AiBatchItemStatus::Running]) === 1;
    }

    public function updateItem(AiBatchItem $item, array $attributes): void
    {
        $item->update($attributes);
    }

    /**
     * 残りの（待機中の）記事を「実行しない」にし、まとめて実行を止める
     */
    public function stop(AiBatch $batch, AiBatchStatus $status, ?string $reason): void
    {
        DB::transaction(function () use ($batch, $status, $reason) {
            AiBatchItem::where('ai_batch_id', $batch->id)->where('status', AiBatchItemStatus::Pending)
                ->update(['status' => AiBatchItemStatus::Skipped, 'message' => $reason]);

            AiBatch::whereKey($batch->id)->where('status', AiBatchStatus::Running)
                ->update(['status' => $status, 'stop_reason' => $reason, 'completed_at' => now()]);
        });
    }

    /**
     * 全ての記事が終わっていれば、完了にする。この呼び出しで完了にした場合だけ true（続けて行う処理を1回だけ行うため）
     */
    public function completeIfFinished(AiBatch $batch): bool
    {
        $unfinished = AiBatchItem::where('ai_batch_id', $batch->id)
            ->whereIn('status', [AiBatchItemStatus::Pending, AiBatchItemStatus::Running])
            ->exists();

        return ! $unfinished && AiBatch::whereKey($batch->id)->where('status', AiBatchStatus::Running)
            ->update(['status' => AiBatchStatus::Completed, 'completed_at' => now()]) === 1;
    }

    public function noteStopReason(AiBatch $batch, string $reason): void
    {
        AiBatch::whereKey($batch->id)->update(['stop_reason' => $reason]);
    }

    /**
     * 成功した記事と、その実行で作った評価
     *
     * @return Collection<int, AiBatchItem>
     */
    public function succeededItemsWithEvaluation(AiBatch $batch): Collection
    {
        return AiBatchItem::with(['post', 'page', 'generation.evaluations'])
            ->where('ai_batch_id', $batch->id)
            ->where('status', AiBatchItemStatus::Succeeded)
            ->orderBy('id')
            ->get();
    }

    /**
     * 今日（日本時間）に自動の再評価で登録した記事の数（1日の上限のため）
     */
    public function autoItemCountSince(int $blogId, DateTimeInterface $from): int
    {
        return AiBatchItem::whereHas('batch', fn ($query) => $query->where('blog_id', $blogId)->where('trigger', AiBatchTrigger::Auto))
            ->where('created_at', '>=', $from)
            ->count();
    }
}
