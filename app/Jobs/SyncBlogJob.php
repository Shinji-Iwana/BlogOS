<?php

namespace App\Jobs;

use App\Enums\SyncTrigger;
use App\Repositories\BlogRepository;
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

    public function handle(BlogRepository $blogs, SyncService $syncService, SyncDispatcher $dispatcher): void
    {
        $dispatcher->markStarted($this->blogId);

        $blog = $blogs->findById($this->blogId);

        // 登録直後に削除・アーカイブされた場合などは何もしない
        if ($blog === null || $blog->isArchived()) {
            return;
        }

        try {
            $syncService->run($blog, $this->trigger, $this->userId, $this->fullRefetch);
        } catch (SyncAlreadyRunningException $e) {
            Log::info('同期：同じブログの同期が実行中のため、今回は実行しませんでした。', ['blog_id' => $this->blogId]);
        }
    }
}
