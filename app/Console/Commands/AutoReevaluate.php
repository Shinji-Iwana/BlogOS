<?php

namespace App\Console\Commands;

use App\Enums\AiBatchTarget;
use App\Enums\AiMode;
use App\Repositories\BlogAiSettingRepository;
use App\Repositories\BlogRepository;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\AiException;
use App\Services\Ai\AutoReevaluationService;
use Illuminate\Console\Command;

/**
 * 条件による自動の再評価（毎日のcron。D-25）。
 *
 * AIの設定で自動の再評価を有効にしたブログだけが対象。--dry-run では、対象の記事と理由を表示するだけで実行しない
 * （無効のブログも表示する）。
 */
class AutoReevaluate extends Command
{
    protected $signature = 'ai:auto-reevaluate
        {--blog=* : 対象のブログID（省略時は全ブログ）}
        {--dry-run : 対象の記事と理由を表示するだけで、実行しない}';

    protected $description = '再評価の条件に当てはまる記事を、自動で品質診断する（AIの設定で有効にしたブログだけ）';

    public function handle(BlogRepository $blogs, BlogAiSettingRepository $settings, AutoReevaluationService $service, AiBatchService $batchService): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogs->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            $setting = $settings->forBlog($blog);
            $label = "{$blog->display_name}（#{$blog->id}・自動の再評価：" . ($setting->auto_reevaluation_enabled ? "有効・{$setting->auto_model}・{$setting->auto_reasoning_effort}" : '無効') . '）';

            if ($this->option('dry-run')) {
                $targets = $batchService->targets($blog, AiMode::QualityDiagnosis, AiBatchTarget::NeedsReevaluation);
                $this->info("{$label}：対象 " . count($targets) . "件（今日の残り " . $service->remainingToday($blog) . '件）');
                foreach ($targets as $row) {
                    $this->line("  [{$row['reason']->label()}] {$row['article']->title_raw}");
                }

                continue;
            }

            try {
                $batch = $service->run($blog);
            } catch (AiException $e) {
                $this->error("{$label}：{$e->getMessage()}");

                continue;
            }

            $this->info($batch !== null ? "{$label}：{$batch->total_count}件を登録しました（まとめて実行 #{$batch->id}）。" : "{$label}：実行しませんでした（無効・対象なし・今日の上限）。");
        }

        return self::SUCCESS;
    }
}
