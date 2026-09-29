<?php

namespace App\Jobs;

use App\Enums\SyncTrigger;
use App\Repositories\BlogRepository;
use App\Services\Schedule\ScheduledTaskService;
use App\Services\Sync\SyncAlreadyRunningException;
use App\Services\Sync\SyncDispatcher;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 1つのブログの同期を、画面の操作とは別に実行する（ARCHITECTURE 28章、D-04-07）。
 *
 * 対象のブログは明示的に受け取る。選択中のブログ（is_selected）は使わない（D-02-05）。
 */
class SyncBlogJob implements ShouldQueue
{
    use Queueable;

    /**
     * 同期は時間がかかるため、Queueの既定より長くする（同期のロックの時間より短くする）
     */
    public int $timeout = 1800;

    /**
     * 失敗した同期を自動で繰り返さない（次の同期か、手動の同期で取り込む）
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $blogId,
        public readonly SyncTrigger $trigger,
        public readonly ?int $userId = null,
        public readonly bool $fullRefetch = false,
    ) {
    }

    public function handle(BlogRepository $blogs, SyncService $syncService, SyncDispatcher $dispatcher, ScheduledTaskService $recorder): void
    {
        $dispatcher->markStarted($this->blogId);

        // 定期実行から登録された同期は、終わったときに定期実行の記録（D-44）に件数を加える
        $recorder->trackJob(function () use ($blogs, $syncService) {
            $blog = $blogs->findById($this->blogId);

            // 登録直後に削除・アーカイブされた場合などは何もしない
            if ($blog === null || $blog->isArchived()) {
                return null;
            }

            try {
                $run = $syncService->run($blog, $this->trigger, $this->userId, $this->fullRefetch);
            } catch (SyncAlreadyRunningException $e) {
                Log::info('同期：同じブログの同期が実行中のため、今回は実行しませんでした。', ['blog_id' => $this->blogId]);

                return ['line' => "[同期] {$blog->home}：同じブログの同期が実行中のため、実行しませんでした"];
            }

            $resources = $run->resources()->get();
            $changed = (int) $resources->sum(fn ($resource) => $resource->created_count + $resource->updated_count + $resource->deleted_count);

            return [
                'processed' => (int) $resources->sum('fetched_count'),
                'changed'   => $changed,
                'errors'    => (int) $resources->sum('error_count'),
                'line'      => "[同期] {$blog->home}：{$run->status->label()}（取得 {$resources->sum('fetched_count')}件・変更 {$changed}件・問題 {$resources->sum('error_count')}件）",
            ];
        });
    }

    /**
     * 時間切れなどで失敗した場合も、定期実行の記録を閉じる
     */
    public function failed(?\Throwable $e): void
    {
        app(ScheduledTaskService::class)->jobFailed($e);
    }
}
