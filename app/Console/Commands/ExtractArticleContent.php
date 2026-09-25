<?php

namespace App\Console\Commands;

use App\Repositories\BlogRepository;
use App\Services\Articles\ContentExtractionService;
use Illuminate\Console\Command;

/**
 * 全記事の本文から、内部リンク・本文中のメディアを抽出し直す（WordPress APIにはアクセスしない）。
 *
 * 通常は同期の中で、詳細を取得した記事だけを抽出する。
 * 抽出の仕組みを入れる前に取り込んだ記事や、抽出の規則を変えた場合に実行する。
 */
class ExtractArticleContent extends Command
{
    protected $signature = 'articles:extract {--blog=* : 対象のブログID（省略時は全ブログ）}';

    protected $description = '全記事の本文から、内部リンク・本文中のメディアを抽出し直す';

    public function handle(BlogRepository $blogRepository, ContentExtractionService $extraction): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));

        foreach ($blogRepository->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            $count = $extraction->extractAll($blog);
            $this->info("[抽出しました] {$blog->home}：{$count}件");
        }

        return self::SUCCESS;
    }
}
