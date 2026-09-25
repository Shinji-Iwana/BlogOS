<?php

namespace App\Jobs;

use App\Enums\SyncTrigger;
use App\Repositories\BlogRepository;
use App\Services\Google\GoogleFetchService;
use App\Services\Sync\SyncAlreadyRunningException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
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

    public static function queuedKey(int $blogId): string
    {
        return "blogos:google:queued:{$blogId}";
    }

    public function handle(BlogRepository $blogs, GoogleFetchService $service): void
    {
        Cache::forget(self::queuedKey($this->blogId));

        $blog = $blogs->findById($this->blogId);
        if ($blog === null || $blog->isArchived()) {
            return;
        }

        try {
            $service->run($blog, $this->trigger, $this->userId);
        } catch (SyncAlreadyRunningException $e) {
            Log::info('Google：同じブログの取得が実行中のため、今回は実行しませんでした。', ['blog_id' => $this->blogId]);
        }
    }
}
