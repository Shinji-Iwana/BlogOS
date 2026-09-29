<?php

namespace App\Console\Commands;

use App\Clients\WordPress\WordPressApiException;
use App\Repositories\BlogRepository;
use App\Services\WordPress\WordPressUpdateCheckService;
use Illuminate\Console\Command;

/**
 * WordPress 本体・プラグイン・テーマの更新の確認（毎日のcron。D-38）。
 */
class CheckWordPressUpdates extends Command
{
    protected $signature = 'wordpress:check-updates
        {--blog=* : 対象のブログID（省略時は全ブログ）}';

    protected $description = 'WordPress 本体・プラグイン・テーマに更新があるかを、WordPress.org の最新のバージョンと比べて調べる';

    public function handle(BlogRepository $blogs, WordPressUpdateCheckService $service): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogs->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            try {
                $result = $service->check($blog);
            } catch (WordPressApiException $e) {
                $this->error("{$blog->display_name}（#{$blog->id}）：{$e->getMessage()}");

                continue;
            }

            $this->info("{$blog->display_name}（#{$blog->id}）：{$result['checked']}件を確認（更新あり {$result['updates']}件・公開停止 {$result['closed']}件）");
        }

        return self::SUCCESS;
    }
}
