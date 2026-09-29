<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Clients\Google\GoogleApiException;
use App\Repositories\BlogRepository;
use App\Services\Google\GoogleIndexInspectionService;
use Illuminate\Console\Command;

/**
 * 記事のインデックスの登録状態を調べる（毎日のcron。D-37）。
 *
 * Search Console を連携したブログだけが対象。まだ調べていない記事・反映で変わった記事・前に調べてから日数が過ぎた記事を、1日の上限まで調べる。
 */
class InspectGoogleIndex extends Command
{
    protected $signature = 'google:inspect-index
        {--blog=* : 対象のブログID（省略時は全ブログ）}
        {--limit= : 調べる記事の上限（省略時は設定の1日の上限）}
        {--all : 日数が過ぎていない記事も調べ直す}';

    protected $description = 'Search Console の URL 検査で、記事のインデックスの登録状態を調べる';

    public function handle(BlogRepository $blogs, GoogleIndexInspectionService $service, ScheduledTaskService $recorder): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogs->getActive() as $blog) {
            if (($ids !== [] && ! in_array($blog->id, $ids, true)) || ! $service->isConfigured($blog)) {
                continue;
            }

            try {
                $result = $service->inspect($blog, $this->option('limit') !== null ? (int) $this->option('limit') : null, (bool) $this->option('all'), 300);
            } catch (GoogleApiException $e) {
                $this->error("{$blog->display_name}（#{$blog->id}）：{$e->getMessage()}");
                $recorder->report(errors: 1, blogs: 1);

                continue;
            }

            $this->info("{$blog->display_name}（#{$blog->id}）：{$result['inspected']}件を調べました（失敗 {$result['errors']}件・残り {$result['remaining']}件）"
                . ($result['stopped'] ? "。途中で止めました：{$result['stopped']}" : ''));
            $recorder->report(processed: $result['inspected'], errors: $result['errors'], blogs: 1);
        }

        return self::SUCCESS;
    }
}
