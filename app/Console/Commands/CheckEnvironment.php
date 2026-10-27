<?php

namespace App\Console\Commands;

use App\Models\EnvironmentCheck;
use App\Services\EnvironmentCheckService;
use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Console\Command;

/**
 * サーバー環境の点検（毎日のcron。D-88）。PHP・BlogOS の部品・Laravel・DB の版を確かめる（料金はかからない）。
 */
class CheckEnvironment extends Command
{
    protected $signature = 'environment:check';

    protected $description = 'PHP・BlogOS の部品・Laravel・DB の、セキュリティの修正とサポートの終わりを確かめる';

    public function handle(EnvironmentCheckService $service, ScheduledTaskService $recorder): int
    {
        $result = $service->check();

        foreach (EnvironmentCheck::orderByRaw("field(target, '" . implode("','", EnvironmentCheck::TARGETS) . "')")->get() as $row) {
            $this->line("  {$row->label}：{$row->levelLabel()}" . ($row->current_version ? "（{$row->current_version}）" : ''));
        }

        $recorder->report(processed: count(EnvironmentCheck::TARGETS), changed: $result['security'] + $result['support']);

        // 読み取れなかった対象があれば失敗にする（定期実行の失敗として、お知らせに出す）
        if ($result['unknown'] > 0) {
            $this->error("読み取れなかった対象が {$result['unknown']}件 あります。");

            return self::FAILURE;
        }

        $this->info("すぐ対応 {$result['security']}件・計画して対応 {$result['support']}件");

        return self::SUCCESS;
    }
}
