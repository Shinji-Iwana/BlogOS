<?php

namespace App\Services\Articles;

use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleContentRepository;

/**
 * 本文からの内部リンク・本文中のメディアの抽出と照合（BLOGOS_WORDPRESS_API.md 15-5、D-08-04、D-15-09）。
 * 同期で投稿・固定ページの詳細を取得したときに使う。WordPress APIへのアクセスは増えない。
 */
class ContentExtractionService
{
    public function __construct(
        protected ContentExtractor $extractor,
        protected ArticleContentRepository $repository,
    ) {
    }

    public function extract(Post|Page $article, Blog $blog): void
    {
        $this->repository->replaceForArticle(
            $article,
            $this->extractor->internalLinks($article->content_raw, $blog->home),
            $this->extractor->images($article->content_raw, $blog->home)
        );
    }

    /**
     * ブログの全記事について抽出し直す（抽出の仕組みを入れる前に取り込んだ記事や、抽出の規則を変えた場合に使う）。
     *
     * @return int 抽出した記事の数
     */
    public function extractAll(Blog $blog): int
    {
        $count = 0;

        foreach ([Post::class, Page::class] as $modelClass) {
            $modelClass::where('blog_id', $blog->id)->existing()->chunkById(100, function ($articles) use ($blog, &$count) {
                foreach ($articles as $article) {
                    $this->extract($article, $blog);
                    $count++;
                }
            });
        }

        $this->resolve($blog->id);

        return $count;
    }

    /**
     * 未解決のリンク先・メディアを照合し直す。
     * 記事・メディアが新しく作成された場合や記事のURLが変わった場合に解決できるよう、同期の最後に毎回行う。
     *
     * @return array{links: int, media: int} 解決できた件数
     */
    public function resolve(int $blogId): array
    {
        return [
            'links' => $this->repository->resolveLinks($blogId),
            'media' => $this->repository->resolveMedia($blogId),
        ];
    }
}
