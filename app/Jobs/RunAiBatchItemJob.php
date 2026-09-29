<?php

namespace App\Jobs;

use App\Clients\OpenAi\OpenAiClient;
use App\Repositories\AiBatchRepository;
use App\Services\Ai\AiBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * まとめて実行の1記事（D-25）。料金がかかるため、失敗しても自動では再実行しない。
 */
class RunAiBatchItemJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public readonly int $itemId,
    ) {
        // 応答を待つ時間に、指示文の作成・取り込みの分を足す
        // 1分あたりの上限で待って送り直す時間（OpenAiClient::MAX_RATE_LIMIT_WAIT）も足す
        $this->timeout = (int) config('blogos.ai.api.timeout') + 120 + OpenAiClient::MAX_RATE_LIMIT_WAIT;
    }

    public function handle(AiBatchRepository $batches, AiBatchService $service): void
    {
        $item = $batches->findItem($this->itemId);
        if ($item !== null) {
            $service->runItem($item);
        }
    }

    public function failed(?Throwable $e): void
    {
        $item = app(AiBatchRepository::class)->findItem($this->itemId);
        if ($item !== null) {
            app(AiBatchService::class)->markItemFailed($item, 'まとめて実行のJobが失敗しました：' . ($e?->getMessage() ?? '不明'));
        }
    }
}
