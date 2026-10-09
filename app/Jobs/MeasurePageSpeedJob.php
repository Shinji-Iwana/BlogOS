<?php

namespace App\Jobs;

use App\Services\PageSpeed\PageSpeedService;
use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 1つの URL を、携帯かデスクトップで1回測る（D-78）。1回に 10〜30秒かかるため、Queue（blogos.pagespeed.queue）で1件ずつ動かす。
 * API の1分の上限を超えないよう、測る前に少し待つ
 */
class MeasurePageSpeedJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly int $blogId,
        public readonly string $url,
        public readonly ?int $postId,
        public readonly ?int $pageId,
        public readonly string $strategy,
        public readonly string $trigger,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(PageSpeedService $service): void
    {
        // 定期実行から登録された測定は、終わったときに定期実行の記録（D-44）に件数を加える
        app(ScheduledTaskService::class)->trackJob(function () use ($service) {
            sleep((int) config('blogos.pagespeed.pause_seconds', 3));
            $run = $service->measure($this->blogId, $this->url, $this->postId, $this->pageId, $this->strategy, $this->trigger, $this->userId);
            $failed = $run->status === 'failed';

            return [
                'processed' => 1,
                'errors'    => $failed ? 1 : 0,
                'line'      => "[PageSpeed] {$this->url}（{$run->strategyLabel()}）：" . ($failed ? "失敗：{$run->error}" : "パフォーマンス {$run->performance_score}・SEO {$run->seo_score}"),
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
