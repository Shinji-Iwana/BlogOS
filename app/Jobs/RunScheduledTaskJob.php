<?php

namespace App\Jobs;

use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 定期実行の「今すぐ実行」（メニューの「設定 → 即時実行」。D-44・D-63-09）。定期実行と同じ処理を Queue で1回実行し、記録する。
 * その日の定期実行が全て終わった後に動く定期実行（after_all）も、これで定期実行として登録する（trigger が scheduled。D-71-09）。
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $taskKey,
        public readonly ?int $userId = null,
        public readonly string $trigger = 'manual',
    ) {
    }

    public function handle(ScheduledTaskService $service): void
    {
        $service->run($this->taskKey, $this->trigger, $this->userId, $this->trigger === 'scheduled' ? now()->startOfMinute() : null);
    }
}
