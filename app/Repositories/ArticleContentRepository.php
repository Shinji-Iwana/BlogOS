<?php

namespace App\Repositories;

use App\Models\ArticleMedia;
use App\Models\InternalLink;
use App\Models\Page;
use App\Models\Post;
use App\Support\ArticlePath;
use Illuminate\Support\Facades\DB;

/**
 * 本文から抽出した内部リンク（internal_links）と本文中のメディア（article_media）。BLOGOS_DATABASE.md 7章。
 */
class ArticleContentRepository
{
    public function __construct(
        protected ArticlePathRepository $paths,
    ) {
    }

    /**
     * 記事の内部リンクと本文中のメディアを作り直す（本文が変わるたびに行う）。
     *
     * @param array<int, array{url: string, anchor_text: string}> $links
     * @param array<int, array{url: string, wordpress_media_id: int|null}> $images
     */
    public function replaceForArticle(Post|Page $article, array $links, array $images): void
    {
        $column = self::articleColumn($article);
        $now = now();

        DB::transaction(function () use ($article, $column, $links, $images, $now) {
            InternalLink::where($column, $article->id)->delete();
            ArticleMedia::where($column, $article->id)->delete();

            InternalLink::insert(array_map(fn (array $link) => [
                'blog_id'     => $article->blog_id,
                $column       => $article->id,
                'target_url'  => $link['url'],
                'anchor_text' => $link['anchor_text'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ], $links));

            ArticleMedia::insert(array_map(fn (array $image) => [
                'blog_id'            => $article->blog_id,
                $column              => $article->id,
                'source_url'         => $image['url'],
                'wordpress_media_id' => $image['wordpress_media_id'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ], $images));
        });
    }

    /**
     * リンク先が未解決の内部リンクを照合する（D-15-09。照合の規則は ArticlePathRepository）。
     *
     * @return int 解決できた件数
     */
    public function resolveLinks(int $blogId): int
    {
        $unresolved = InternalLink::where('blog_id', $blogId)
            ->whereNull('target_post_id')
            ->whereNull('target_page_id')
            ->get(['id', 'target_url']);

        if ($unresolved->isEmpty()) {
            return 0;
        }

        $resolved = 0;
        foreach ($unresolved as $link) {
            $match = $this->paths->find($blogId, $link->target_url);

            if ($match !== null) {
                $link->update(['target_' . $match[0] => $match[1]]);
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * 対応するメディアが未解決の本文中のメディアを照合する。
     * wp-image-<ID> のクラスがあればそのIDで、なければURL（縮小版のURLは元のファイルのURLに戻して）で照合する。
     *
     * @return int 解決できた件数
     */
    public function resolveMedia(int $blogId): int
    {
        $unresolved = ArticleMedia::where('blog_id', $blogId)->whereNull('media_id')->get(['id', 'source_url', 'wordpress_media_id']);

        if ($unresolved->isEmpty()) {
            return 0;
        }

        $byWordPressId = DB::table('media')->where('blog_id', $blogId)->pluck('id', 'wordpress_id');
        $byUrl = DB::table('media')->where('blog_id', $blogId)->whereNotNull('source_url')->pluck('id', 'source_url');

        $resolved = 0;
        foreach ($unresolved as $media) {
            $mediaId = null;

            if ($media->wordpress_media_id !== null) {
                $mediaId = $byWordPressId[$media->wordpress_media_id] ?? null;
            }

            if ($mediaId === null) {
                $original = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', strtok($media->source_url, '?'));
                $mediaId = $byUrl[$media->source_url] ?? $byUrl[$original] ?? null;
            }

            if ($mediaId !== null) {
                $media->update(['media_id' => $mediaId]);
                $resolved++;
            }
        }

        return $resolved;
    }

    public static function articleColumn(Post|Page $article): string
    {
        return $article instanceof Post ? 'post_id' : 'page_id';
    }
}
