<?php

namespace App\Services\Ai\Executors;

use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use App\Repositories\AiGenerationRepository;

/**
 * 手動実行：指示文を画面に表示し、利用者がChatGPT等で実行した回答を貼り付けるのを待つ（D-07-06）。
 */
class ManualExecutor implements AiExecutor
{
    public function __construct(
        protected AiGenerationRepository $generations,
    ) {
    }

    public function start(AiGeneration $generation): void
    {
        $this->generations->update($generation, ['status' => AiGenerationStatus::WaitingOutput, 'started_at' => now()]);
    }
}
