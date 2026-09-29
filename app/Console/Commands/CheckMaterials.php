<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Repositories\BlogAiSettingRepository;
use App\Repositories\BlogRepository;
use App\Services\Materials\MaterialCheckService;
use Illuminate\Console\Command;

/**
 * 教材の定期チェック（毎日のcron。D-30）。
 *
 * AIの設定で教材の定期チェックを有効にしたブログだけが対象。前回の調査から一定の期間が過ぎた教材を、1日に上限の数まで調べ直す。
 * --dry-run では、今日調べる教材を表示するだけで実行しない（無効のブログも表示する）。
 */
class CheckMaterials extends Command
{
    protected $signature = 'materials:check
        {--blog=* : 対象のブログID（省略時は全ブログ）}
        {--dry-run : 今日調べる教材を表示するだけで、実行しない}';

    protected $description = '前回の調査から期間が過ぎた教材を、AIで調べ直す（AIの設定で有効にしたブログだけ）';

    public function handle(BlogRepository $blogs, BlogAiSettingRepository $settings, MaterialCheckService $service, ScheduledTaskService $recorder): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogs->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            $label = "{$blog->display_name}（#{$blog->id}・教材の定期チェック：" . ($settings->forBlog($blog)->material_check_enabled ? '有効' : '無効') . '）';

            if ($this->option('dry-run')) {
                $due = $service->due($blog);
                $this->info("{$label}：今日調べる教材 {$due->count()}件");
                foreach ($due as $material) {
                    $this->line("  {$material->kind->label()}「{$material->name}」（前回の調査：" . ($material->researched_at?->toDateString() ?? 'なし') . '）');
                }

                continue;
            }

            $result = $service->run($blog);
            $this->info("{$label}：{$result['started']}件の調査を始めました。");
            foreach ($result['errors'] as $error) {
                $this->error("  {$error}");
            }
            $recorder->report(processed: $result['started'], errors: count($result['errors']), blogs: 1);
        }

        return self::SUCCESS;
    }
}
