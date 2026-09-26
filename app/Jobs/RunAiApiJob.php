<?php

namespace App\Jobs;

use App\Repositories\AiGenerationRepository;
use App\Services\Ai\AiRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * BlogOSのAI機能のAPI実行（D-07-08、D-24）。人が画面で実行したときだけ登録される（D-07-02）。
 *
 * 料金がかかるため、失敗しても自動では再実行しない（人が画面から実行し直す）。
 */
class RunAiApiJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public readonly int $generationId,
    ) {
        // 応答を待つ時間に、前後の処理の分を足す
        $this->timeout = (int) config('blogos.ai.api.timeout') + 120;
    }

    public function handle(AiGenerationRepository $generations, AiRunService $service): void
    {
        $generation = $generations->find($this->generationId);
        if ($generation !== null) {
            $service->runApi($generation);
        }
    }

    /**
     * 時間切れなど、Job自体が失敗した場合は、実行記録を失敗にする
     */
    public function failed(?Throwable $e): void
    {
        $generation = app(AiGenerationRepository::class)->find($this->generationId);
        if ($generation !== null) {
            app(AiRunService::class)->markApiFailed($generation, 'API実行のJobが失敗しました：' . ($e?->getMessage() ?? '不明'));
        }
    }
}
