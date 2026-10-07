<?php

namespace App\Repositories;

use App\Enums\GoogleService;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\GoogleFetchRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Googleのデータの取得の実行記録（google_fetch_runs）。
 */
class GoogleFetchRunRepository
{
    public function start(int $blogId, GoogleService $service, SyncTrigger $trigger, Carbon $from, Carbon $to, ?int $userId): GoogleFetchRun
    {
        return GoogleFetchRun::create([
            'blog_id'      => $blogId,
            'service'      => $service,
            'trigger'      => $trigger,
            'status'       => SyncStatus::Running,
            'date_from'    => $from->toDateString(),
            'date_to'      => $to->toDateString(),
            'triggered_by' => $userId,
            'started_at'   => now(),
        ]);
    }

    public function finish(GoogleFetchRun $run, SyncStatus $status, int $rowCount, ?string $message = null, ?int $errorStatus = null, ?string $errorBody = null): void
    {
        $run->update([
            'status'       => $status,
            'row_count'    => $rowCount,
            'message'      => $message,
            'error_status' => $errorStatus,
            'error_body'   => $errorBody,
            'finished_at'  => now(),
        ]);
    }

    /**
     * @return Collection<string, GoogleFetchRun> サービスごとの最新の実行記録
     */
    public function latestForBlog(int $blogId): Collection
    {
        return GoogleFetchRun::where('blog_id', $blogId)
            ->whereIn('id', GoogleFetchRun::selectRaw('max(id)')->where('blog_id', $blogId)->groupBy('service'))
            ->get()
            ->keyBy(fn (GoogleFetchRun $run) => $run->service->value);
    }

    /**
     * @return LengthAwarePaginator<int, GoogleFetchRun>（新しい順。ページに分ける）
     */
    public function paginateForBlog(int $blogId, int $perPage): LengthAwarePaginator
    {
        return GoogleFetchRun::where('blog_id', $blogId)->orderByDesc('id')->paginate($perPage);
    }
}
