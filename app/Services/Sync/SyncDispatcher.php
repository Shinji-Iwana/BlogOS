<?php

namespace App\Services\Sync;

use App\Enums\SyncTrigger;
use App\Jobs\SyncBlogJob;
use Illuminate\Support\Facades\Cache;

/**
 * 同期のJobをQueueに登録する（D-01-03、D-04-07）。
 *
 * Queueに登録してから処理が始まるまで（cronの queue:work が起動するまで）の間は、
 * sync_runs に記録がないため、「待機中」であることを別に覚えておき、画面に表示する。
 */
class SyncDispatcher
{
    /**
     * 待機中の記録を残す時間。Queueの処理が止まっていた場合でも、いつまでも「待機中」と表示しないため
     */
    protected const QUEUED_SECONDS = 86400;

    public function dispatch(int $blogId, SyncTrigger $trigger, ?int $userId = null): void
    {
        Cache::put($this->key($blogId), now()->toIso8601String(), self::QUEUED_SECONDS);

        SyncBlogJob::dispatch($blogId, $trigger, $userId);
    }

    /**
     * 待機中であれば、登録した日時（ISO 8601）を返す
     */
    public function queuedAt(int $blogId): ?string
    {
        return Cache::get($this->key($blogId));
    }

    /**
     * Jobの処理が始まったときに呼ぶ
     */
    public function markStarted(int $blogId): void
    {
        Cache::forget($this->key($blogId));
    }

    protected function key(int $blogId): string
    {
        return "blogos:sync:queued:{$blogId}";
    }
}
