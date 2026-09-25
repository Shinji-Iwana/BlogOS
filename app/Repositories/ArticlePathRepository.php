<?php

namespace App\Repositories;

use App\Models\Page;
use App\Models\Post;
use App\Support\ArticlePath;
use Illuminate\Support\Facades\DB;

/**
 * URL（パス）から記事（投稿・固定ページ）を探す（D-08-05、D-15-09、D-21-08）。
 *
 * 照合の順：
 * 1. 記事の現在のパス（normalized_path）
 * 2. 履歴に残っている過去の link のパス
 * 3. パスの最後の部分（例：483.html、スラッグ）が、ただ1つの記事の最後の部分と一致する場合。
 *    カテゴリのスラッグの変更などで、パスの途中だけが変わった過去のURLを照合するため。
 *    カテゴリ・タグ・投稿者・ページ送りのページは対象外とする。
 */
class ArticlePathRepository
{
    /**
     * 最後の部分での照合をしないパスの始まり（記事以外のページ）
     */
    protected const NON_ARTICLE_PREFIXES = ['/category/', '/tag/', '/author/', '/page/', '/feed', '/wp-'];

    /** @var array<int, array{paths: array<string, array{0: string, 1: int}>, segments: array<string, array{0: string, 1: int}|false>}> */
    protected array $maps = [];

    /**
     * @return array{0: string, 1: int}|null [列（post_id / page_id）, 記事のID]
     */
    public function find(int $blogId, ?string $url): ?array
    {
        $path = ArticlePath::fromUrl($url);

        if ($path === null || $path === '/') {
            return null;
        }

        $map = $this->map($blogId);

        if (isset($map['paths'][$path])) {
            return $map['paths'][$path];
        }

        foreach (self::NON_ARTICLE_PREFIXES as $prefix) {
            if (str_starts_with($path . '/', $prefix)) {
                return null;
            }
        }

        $segment = self::lastSegment($path);
        $match = $segment === null ? null : ($map['segments'][$segment] ?? null);

        return $match === false ? null : $match;
    }

    /**
     * 記事が追加・変更された後に、読み込み直す
     */
    public function forget(int $blogId): void
    {
        unset($this->maps[$blogId]);
    }

    protected function map(int $blogId): array
    {
        if (isset($this->maps[$blogId])) {
            return $this->maps[$blogId];
        }

        $paths = [];

        // 過去の link（現在のパスで上書きされるよう、先に入れる）
        foreach (['post_histories' => 'post_id', 'page_histories' => 'page_id'] as $historyTable => $column) {
            foreach (DB::table($historyTable)->where('blog_id', $blogId)->where('field', 'link')->whereNotNull('old_value')->get([$column, 'old_value']) as $row) {
                $path = ArticlePath::fromUrl($row->old_value);
                if ($path !== null) {
                    $paths[$path] = [$column, (int) $row->{$column}];
                }
            }
        }

        // 現在のパス（削除されていない記事を優先する）
        $segments = [];
        foreach ([Page::class => 'page_id', Post::class => 'post_id'] as $modelClass => $column) {
            $modelClass::where('blog_id', $blogId)
                ->whereNotNull('normalized_path')
                ->orderByRaw('wordpress_deleted_at is null')
                ->get(['id', 'normalized_path'])
                ->each(function ($article) use (&$paths, &$segments, $column) {
                    $paths[$article->normalized_path] = [$column, $article->id];

                    $segment = self::lastSegment($article->normalized_path);
                    if ($segment !== null) {
                        // 同じ最後の部分を持つ記事が複数ある場合は、照合に使わない（false）
                        $existing = $segments[$segment] ?? null;
                        $segments[$segment] = $existing === null || $existing === [$column, $article->id] ? [$column, $article->id] : false;
                    }
                });
        }

        return $this->maps[$blogId] = ['paths' => $paths, 'segments' => $segments];
    }

    public static function lastSegment(string $path): ?string
    {
        $segment = basename(rtrim($path, '/'));

        return $segment === '' || $segment === '/' ? null : $segment;
    }
}
