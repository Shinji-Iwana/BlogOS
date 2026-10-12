<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Services\ServerStatusService;
use Illuminate\Console\Command;

/**
 * BlogOS の DB の使用量の、ブログごとの内訳を計算して保存する（D-71-09）。
 *
 * テーブルの全ての行のデータの長さを数えるため重い。定期実行では、その日の定期実行が全て終わった後に動く（ScheduledTaskService::startAfterAll）。
 * XServer情報の画面は、最新の計算の結果を読むだけ。
 */
class MeasureDatabaseUsage extends Command
{
    protected $signature = 'database:measure-usage';

    protected $description = 'BlogOS の DB の使用量の、ブログごとの内訳を計算して保存する';

    public function handle(ServerStatusService $status, ScheduledTaskService $recorder): int
    {
        $measurement = $status->measureUsage();

        $tables = $measurement->table_count;
        $recorder->report(processed: $tables);
        $this->info(sprintf('%dテーブルの内訳を計算しました（%.1f秒。DB 全体 %.1f MB）。', $tables, $measurement->duration_ms / 1000, $measurement->total_bytes / 1048576));

        return self::SUCCESS;
    }
}
