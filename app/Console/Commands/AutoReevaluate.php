<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Enums\AiBatchTarget;
use App\Enums\AiMode;
use App\Repositories\BlogAiSettingRepository;
use App\Repositories\BlogRepository;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\AiException;
use App\Services\Ai\AutoReevaluationService;
use App\Services\Articles\RoadmapLinkService;
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

    public function handle(BlogRepository $blogs, BlogAiSettingRepository $settings, AutoReevaluationService $service, AiBatchService $batchService, ScheduledTaskService $recorder, RoadmapLinkService $roadmapLinks): int
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
                    $this->line("  [{$row['reason']->label()}]" . ($row['priority_notes'] ? '（優先：' . implode('・', $row['priority_notes']) . '）' : '') . " {$row['article']->title_raw}");
                }
                // ロードマップに載せる記事（D-70-06）
                foreach ($roadmapLinks->targets($blog) as $group) {
                    $this->line("  [ロードマップに載せる] 「{$group['page']->title_raw}」に " . count($group['posts']) . '件：' . collect($group['posts'])->pluck('title_raw')->implode('、'));
                }

                continue;
            }

            try {
                $batch = $service->run($blog);
            } catch (AiException $e) {
                $this->error("{$label}：{$e->getMessage()}");
                $recorder->report(errors: 1, blogs: 1);

                continue;
            }

            $this->info($batch !== null ? "{$label}：{$batch->total_count}件を登録しました（まとめて実行 #{$batch->id}）。" : "{$label}：実行しませんでした（無効・対象なし・今日の上限）。");

            // 改修まで有効なら、ロードマップに載っていない記事を、子ロードマップの編集案で載せる（孤立記事をなくす。D-70-06）
            $roadmaps = 0;
            if ($setting->auto_reevaluation_enabled && $setting->auto_revision_enabled && ! $blog->isArchived()) {
                $defaults = (array) config('blogos.ai.api.defaults.revision');
                foreach ($roadmapLinks->run($blog, $setting->auto_revision_model ?: $defaults['model'], $setting->auto_revision_reasoning_effort ?: $defaults['effort']) as $line) {
                    $this->line("  {$line}");
                    $roadmaps += str_contains($line, 'を登録しました') ? 1 : 0;
                }
            }
            $recorder->report(processed: (int) ($batch?->total_count ?? 0) + $roadmaps, blogs: 1);
        }

        return self::SUCCESS;
    }
}
