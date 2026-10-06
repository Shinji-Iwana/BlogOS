<?php

namespace App\Console\Commands;

use App\Services\Schedule\ScheduledTaskService;
use App\Enums\SyncTrigger;
use App\Jobs\SyncBlogJob;
use App\Repositories\BlogRepository;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Console\Command;

/**
 * アーカイブしていない全ブログの同期を、Queueに登録する（毎日のcron。D-01-03、D-04-07）。
 *
 * --now を付けると、Queueを使わずにその場で実行する（手元での確認用）。
 */
class SyncBlogs extends Command
{
    protected $signature = 'blogs:sync {--blog=* : 対象のブログID（省略時は全ブログ）} {--now : Queueを使わずにその場で実行する} {--full : 更新日時に関係なく、全ての記事・メディアの詳細を取得し直す（保存する項目を増やしたときなど）}';

    protected $description = 'WordPressと同期する（アーカイブしていないブログが対象）';

    public function handle(BlogRepository $blogRepository, SyncDispatcher $dispatcher, ScheduledTaskService $recorder): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));
        $count = 0;

        foreach ($blogRepository->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            if ($this->option('now') || $this->option('full')) {
                dispatch_sync(new SyncBlogJob($blog->id, SyncTrigger::Manual, null, (bool) $this->option('full')));
                $this->info("[同期しました] {$blog->home}");
            } else {
                // 定期実行の記録（D-44）は、Queue の同期が終わったときに閉じる
                $recorder->addPendingJob();
                // メニューの即時実行なら、同期の記録の契機は手動（D-63-18）
                $dispatcher->dispatch($blog->id, $recorder->syncTrigger(), $recorder->requestedBy());
                $this->info("[同期を登録しました] {$blog->home}");
            }
            $count++;
        }
        $recorder->report(blogs: $count);

        return self::SUCCESS;
    }
}
