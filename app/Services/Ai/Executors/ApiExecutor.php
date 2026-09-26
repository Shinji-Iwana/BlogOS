<?php

namespace App\Services\Ai\Executors;

use App\Jobs\RunAiApiJob;
use App\Models\AiGeneration;

/**
 * API実行（D-07-06、D-07-08、D-24）：Jobとして OpenAI API を呼び、回答を取り込む。
 *
 * APIキー・費用の上限の確認は、実行記録を作る前に AiRunService が AiApiPolicy で行う。
 * Jobは Queue の処理（cronの queue:work）で動く。
 */
class ApiExecutor implements AiExecutor
{
    public function start(AiGeneration $generation): void
    {
        RunAiApiJob::dispatch($generation->id)->afterCommit();
    }
}
