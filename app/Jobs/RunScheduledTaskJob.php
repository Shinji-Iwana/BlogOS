<?php

namespace App\Jobs;

use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 画面「定期実行」の「今すぐ実行」（D-44）。定期実行と同じ処理を Queue で1回実行し、記録する。
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $taskKey,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(ScheduledTaskService $service): void
    {
        $service->run($this->taskKey, 'manual', $this->userId);
    }
}
