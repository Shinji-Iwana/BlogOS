<?php

namespace App\Repositories;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Models\SyncRun;
use App\Models\SyncRunResource;
use Illuminate\Database\Eloquent\Collection;

class SyncRunRepository
{
    public function start(Blog $blog, SyncTrigger $trigger, ?int $userId): SyncRun
    {
        return SyncRun::create([
            'blog_id'      => $blog->id,
            'trigger'      => $trigger,
            'status'       => SyncStatus::Running,
            'started_at'   => now(),
            'triggered_by' => $userId,
        ]);
    }

    public function finish(SyncRun $run, SyncStatus $status, ?string $message = null): SyncRun
    {
        $run->update([
            'status'      => $status,
            'finished_at' => now(),
            'message'     => $message ?? $run->message,
        ]);

        return $run;
    }

    /**
     * 実行中の記録に補足を残す（回復処理の結果など）
     */
    public function note(SyncRun $run, string $message): void
    {
        $run->update(['message' => $message]);
    }

    public function startResource(SyncRun $run, string $resourceType): SyncRunResource
    {
        return SyncRunResource::create([
            'sync_run_id'   => $run->id,
            'resource_type' => $resourceType,
            'status'        => SyncStatus::Running,
            'started_at'    => now(),
        ]);
    }

    /**
     * @param array<string, int> $counts fetched / created / updated / unchanged / deleted / error
     */
    public function finishResource(SyncRunResource $resource, SyncStatus $status, array $counts, ?string $message = null): void
    {
        $values = ['status' => $status, 'message' => $message, 'finished_at' => now()];

        foreach ($counts as $key => $count) {
            $values["{$key}_count"] = $count;
        }

        $resource->update($values);
    }

    public function latestForBlog(int $blogId): ?SyncRun
    {
        return SyncRun::with('resources')
            ->where('blog_id', $blogId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    public function isRunning(int $blogId): bool
    {
        return SyncRun::where('blog_id', $blogId)->where('status', SyncStatus::Running)->exists();
    }

    public function recentForBlog(int $blogId, int $limit = 50): Collection
    {
        return SyncRun::with('resources')
            ->where('blog_id', $blogId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 異常終了などで「実行中」のまま残った記録を「失敗」にする（ロックの解除時間を過ぎたもの）。
     */
    public function failStale(int $blogId, int $staleSeconds): void
    {
        SyncRun::where('blog_id', $blogId)
            ->where('status', SyncStatus::Running)
            ->where('started_at', '<', now()->subSeconds($staleSeconds))
            ->update([
                'status'      => SyncStatus::Failed,
                'finished_at' => now(),
                'message'     => '実行中のまま止まっていたため、失敗として扱いました。',
            ]);
    }
}
