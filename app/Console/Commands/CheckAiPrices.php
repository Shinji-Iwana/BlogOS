<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Services\Ai\AiPriceCheckService;
use Illuminate\Console\Command;

/**
 * API実行の料金表を、OpenAIの公式のページと照合する（毎日のcron。D-31-03）。
 *
 * 値上がりは自動で反映し、値下がりは画面（AIの設定）で人が確認して反映する。--dry-run では、差を表示するだけで保存しない。
 */
class CheckAiPrices extends Command
{
    protected $signature = 'ai:check-prices
        {--dry-run : 差を表示するだけで、保存しない}';

    protected $description = 'API実行の料金表を、OpenAIの公式のページと照合する（値上がりは自動で反映、値下がりは人が確認）';

    public function handle(AiPriceCheckService $service, ScheduledTaskService $recorder): int
    {
        $result = $service->check((bool) $this->option('dry-run'));

        foreach ($result['messages'] as $message) {
            $this->line("  {$message}");
        }

        $summary = "値上がり（自動で反映）{$result['applied']}件・値下がり（確認待ち）{$result['pending']}件";
        $recorder->report(changed: $result['applied'] + $result['pending']);
        if ($result['status'] === 'failed') {
            $this->error("照合できなかった料金があります。{$summary}");

            return self::FAILURE;
        }

        $this->info($result['applied'] + $result['pending'] === 0 ? '料金表は公式のページと同じです。' : $summary);

        return self::SUCCESS;
    }
}
