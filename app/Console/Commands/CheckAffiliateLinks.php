<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Enums\AffiliateLinkCheckResult;
use App\Repositories\BlogRepository;
use App\Services\Materials\AffiliateLinkChecker;
use Illuminate\Console\Command;

/**
 * アフィリエイトのプログラムのリンクの定期確認（週1回のcron。D-33-09）。
 *
 * 記事で使っているプログラム（提携中・未確認）ごとに、リンクを1本だけ開き、提携が終わっていないかを確かめる。
 * 疑いがあれば、トップページと「アフィリエイトのプログラム」の画面で知らせる（状態は自動では変えない）。
 */
class CheckAffiliateLinks extends Command
{
    protected $signature = 'affiliate:check-links
        {--blog=* : 対象のブログID（省略時は全ブログ）}';

    protected $description = 'アフィリエイトのプログラムのリンクを確かめ、提携が終わっていないかを調べる';

    public function handle(BlogRepository $blogs, AffiliateLinkChecker $checker, ScheduledTaskService $recorder): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogs->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            // 同じサイトへ続けて送らないよう、1本ごとに少し待つ
            $results = $checker->checkBlog($blog, 1000);
            $suspects = array_keys(array_filter($results, fn ($result) => $result === AffiliateLinkCheckResult::Suspect));
            $this->info("{$blog->display_name}（#{$blog->id}）：" . count($results) . '件を確認' . ($suspects !== [] ? '。提携終了の疑い：' . implode('、', $suspects) : ''));
            $recorder->report(processed: count($results), changed: count($suspects), blogs: 1);
        }

        return self::SUCCESS;
    }
}
