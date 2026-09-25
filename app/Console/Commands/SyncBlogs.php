<?php

namespace App\Console\Commands;

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
    protected $signature = 'blogs:sync {--blog=* : 対象のブログID（省略時は全ブログ）} {--now : Queueを使わずにその場で実行する}';

    protected $description = 'WordPressと同期する（アーカイブしていないブログが対象）';

    public function handle(BlogRepository $blogRepository, SyncDispatcher $dispatcher): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogRepository->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            if ($this->option('now')) {
                dispatch_sync(new SyncBlogJob($blog->id, SyncTrigger::Scheduled));
                $this->info("[同期しました] {$blog->home}");
            } else {
                $dispatcher->dispatch($blog->id, SyncTrigger::Scheduled);
                $this->info("[同期を登録しました] {$blog->home}");
            }
        }

        return self::SUCCESS;
    }
}
