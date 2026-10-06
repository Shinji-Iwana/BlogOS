<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Services\Schedule\ScheduledTaskService;
use App\Repositories\BlogRepository;
use App\Services\Google\GoogleFetchService;
use App\Services\Sync\SyncAlreadyRunningException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 1つのブログのGoogleのデータの取得（D-21-07）。対象のブログは明示的に受け取る（D-02-05）。
 */
class GoogleFetchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $blogId,
        public readonly SyncTrigger $trigger,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(BlogRepository $blogs, GoogleFetchService $service): void
    {
        // 定期実行から登録された取得は、終わったときに定期実行の記録（D-44）に件数を加える
        app(ScheduledTaskService::class)->trackJob(function () use ($blogs, $service) {
            $blog = $blogs->findById($this->blogId);
            if ($blog === null || $blog->isArchived()) {
                return null;
            }

            try {
                $results = $service->run($blog, $this->trigger, $this->userId);
            } catch (SyncAlreadyRunningException $e) {
                Log::info('Google：同じブログの取得が実行中のため、今回は実行しませんでした。', ['blog_id' => $this->blogId]);

                return ['line' => "[Google] {$blog->home}：同じブログの取得が実行中のため、実行しませんでした"];
            }

            $rows = array_sum(array_column($results, 'rows'));
            $failed = array_keys(array_filter($results, fn ($result) => $result['status'] === SyncStatus::Failed));

            return [
                'processed' => $rows,
                'errors'    => count($failed),
                'line'      => "[Google] {$blog->home}：{$rows}行" . ($failed !== [] ? '（失敗：' . implode('、', $failed) . '）' : ''),
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
