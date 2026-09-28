<?php

namespace App\Jobs;

use App\Repositories\AiGenerationRepository;
use App\Services\Images\ImageGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 画像モデルで画像を作る（D-32）。人が画面で実行したときだけ登録される。
 *
 * 料金がかかるため、失敗しても自動では再実行しない（人が画像の画面から作り直す）。
 */
class GenerateImageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public readonly int $generationId,
    ) {
        $this->timeout = (int) config('blogos.ai.api.timeout') + 120;
    }

    public function handle(AiGenerationRepository $generations, ImageGenerationService $service): void
    {
        $generation = $generations->find($this->generationId);
        if ($generation !== null) {
            $service->run($generation);
        }
    }

    public function failed(?Throwable $e): void
    {
        $generation = app(AiGenerationRepository::class)->find($this->generationId);
        if ($generation !== null) {
            app(ImageGenerationService::class)->markFailed($generation, '画像の生成のJobが失敗しました：' . ($e?->getMessage() ?? '不明'));
        }
    }
}
