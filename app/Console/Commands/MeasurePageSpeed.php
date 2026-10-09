<?php

namespace App\Console\Commands;

use App\Repositories\BlogRepository;
use App\Services\PageSpeed\PageSpeedService;
use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Console\Command;

/**
 * 記事とトップページを、PageSpeed Insights で測る（毎週の定期実行。D-78）。
 *
 * 測る URL を選び、URL×携帯・デスクトップごとに Queue に登録する（測るのは Queue。1回に 10〜30秒かかるため）。
 * 1回で測る記事は上限まで（まだ測っていない記事 → 前回の測定の後に更新された記事 → 前回の測定が古い記事の順）。トップページは毎回測る。
 */
class MeasurePageSpeed extends Command
{
    protected $signature = 'pagespeed:measure
        {--blog=* : 対象のブログID（省略時は全ブログ）}
        {--limit= : 測る記事の URL の上限（省略時は設定の上限）}';

    protected $description = 'PageSpeed Insights で、記事とトップページの表示の速さなどを測る（Queue に登録する）';

    public function handle(BlogRepository $blogs, PageSpeedService $service, ScheduledTaskService $recorder): int
    {
        if (! $service->isConfigured()) {
            $this->error('PageSpeed Insights の APIキーが設定されていません（.env の GOOGLE_PAGESPEED_API_KEY）。');
            $recorder->report(errors: 1);

            return self::FAILURE;
        }

        $ids = array_map('intval', (array) $this->option('blog'));
        $targets = $blogs->getActive()->filter(fn ($blog) => $ids === [] || in_array($blog->id, $ids, true))->values();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : (int) config('blogos.pagespeed.max_urls_per_run', 100);

        $due = $service->due($targets, $limit);
        $count = $service->dispatch($due, $recorder->syncTrigger()->value === 'manual' ? 'manual' : 'scheduled', $recorder->requestedBy());
        $recorder->report(blogs: $targets->count());

        $this->info(count($due) . '件の URL（トップページ ' . $targets->count() . '件を含む）を、携帯・デスクトップで測るよう Queue に登録しました（' . $count . '回）。');

        return self::SUCCESS;
    }
}
