<?php

namespace App\Services\Ai\Executors;

use App\Models\AiGeneration;
use App\Services\Ai\AiException;

/**
 * AIの実行方式（手動／API）の違いを吸収する（ARCHITECTURE 18-3、D-07-06）。
 */
interface AiExecutor
{
    /**
     * 指示文（input）を保存済みの実行記録を受け取り、実行を始める。
     * 手動実行では回答の貼り付けを待つ状態にし、API実行ではJobとして実行する。
     *
     * @throws AiException
     */
    public function start(AiGeneration $generation): void;
}
