<?php

namespace App\Services\Sync;

use App\Enums\SyncStatus;
use App\Models\Blog;
use App\Models\SyncRunResource;
use App\Repositories\SyncIssueRepository;
use App\Repositories\SyncRunRepository;

/**
 * 同期の状態（ダッシュボードの通知・進み具合の表示）。
 *
 * DBとキャッシュから読むだけで、WordPress APIは呼ばない（D-01-05）。
 */
class SyncStatusService
{
    public function __construct(
        protected SyncRunRepository $runs,
        protected SyncIssueRepository $issues,
        protected SyncDispatcher $dispatcher,
    ) {
    }

    /**
     * @return array{
     *     state: string,
     *     queued_at: string|null,
     *     latest_run: array|null,
     *     unresolved_issue_count: int
     * }
     */
    public function forBlog(Blog $blog): array
    {
        $run = $this->runs->latestForBlog($blog->id);
        $queuedAt = $this->dispatcher->queuedAt($blog->id);

        // running：実行中 / queued：Queueに登録済みで開始待ち / idle：どちらでもない
        $state = match (true) {
            $run?->status === SyncStatus::Running => 'running',
            $queuedAt !== null                    => 'queued',
            default                               => 'idle',
        };

        return [
            'state'                  => $state,
            'queued_at'              => $queuedAt,
            'latest_run'             => $run === null ? null : [
                'id'            => $run->id,
                'trigger'       => $run->trigger->value,
                'trigger_label' => $run->trigger->label(),
                'status'        => $run->status->value,
                'status_label'  => $run->status->label(),
                'started_at'    => $run->started_at?->toIso8601String(),
                'finished_at'   => $run->finished_at?->toIso8601String(),
                'resources'     => $run->resources->map(fn (SyncRunResource $resource) => [
                    'resource_type' => $resource->resource_type,
                    'status'        => $resource->status->value,
                    'status_label'  => $resource->status->label(),
                    'fetched'       => $resource->fetched_count,
                    'created'       => $resource->created_count,
                    'updated'       => $resource->updated_count,
                    'unchanged'     => $resource->unchanged_count,
                    'deleted'       => $resource->deleted_count,
                    'error'         => $resource->error_count,
                    'message'       => $resource->message,
                ])->values()->all(),
            ],
            'unresolved_issue_count' => $this->issues->countUnresolved($blog->id),
        ];
    }
}
