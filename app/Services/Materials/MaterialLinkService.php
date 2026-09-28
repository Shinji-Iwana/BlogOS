<?php

namespace App\Services\Materials;

use App\Enums\MaterialKind;
use App\Models\Blog;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\MaterialRepository;

/**
 * 記事の本文にある教材のリンクと、登録した教材の照合（D-30）。
 *
 * 1. 既存の記事から、使われている教材のリンクを教材ごとにまとめる（登録の手間を減らすため）
 * 2. 記事ごとに、本文にある教材を article_materials に記録する（同期で本文が変わるたびと、教材を登録・更新したとき）
 */
class MaterialLinkService
{
    /**
     * @var array<int, array<string, int>> ブログごとの「リンクの識別子 => 教材のID」
     */
    protected array $keyMaps = [];

    public function __construct(
        protected MaterialLinkScanner $scanner,
        protected MaterialRepository $materials,
    ) {
    }

    /**
     * 既存の記事から検出した教材のリンクを、教材ごとにまとめる。
     * 同じ見出しの下にある Amazon と楽天のリンクや、別の記事で組み合わせて使われているリンクは、同じ教材とみなす
     *
     * @return list<array{keys: list<string>, kind: MaterialKind, name: string, names: array<string, int>, amazon_url: string|null, rakuten_url: string|null, affiliate_url: string|null, extra_urls: list<string>, articles: list<Post|Page>, material: Material|null}>
     */
    public function detect(Blog $blog): array
    {
        // 識別子どうしを結ぶ（Union-Find）
        $parent = [];
        $find = function (string $key) use (&$parent, &$find): string {
            $parent[$key] ??= $key;

            return $parent[$key] === $key ? $key : ($parent[$key] = $find($parent[$key]));
        };

        $links = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->get(['id', 'blog_id', 'title_raw', 'link', 'status', 'content_raw']) as $article) {
                $groups = [];
                foreach ($this->scanner->scan((string) $article->content_raw) as $link) {
                    $links[] = $link + ['article' => $article];
                    $groups[$link['group']][] = $link['key'];
                }
                foreach ($groups as $keys) {
                    foreach ($keys as $key) {
                        $parent[$find($key)] = $find($keys[0]);
                    }
                }
            }
        }

        // Udemy は、講座名が同じならリンクが違っても同じ講座とみなす（同じ講座の紹介リンクを作り直した場合など）
        foreach ($links as $link) {
            if ($link['kind'] === MaterialKind::Udemy && $link['name'] !== null) {
                $parent[$find('name:udemy:' . $link['name'])] = $find($link['key']);
            }
        }

        // 画面の表示のたびに、登録済みの教材を読み直す
        $this->forget($blog->id);
        $keyMap = $this->keyMap($blog->id);
        $materials = $this->materials->allForBlog($blog->id)->keyBy('id');

        $clusters = [];
        foreach ($links as $link) {
            $root = $find($link['key']);
            $cluster = &$clusters[$root];
            $cluster['keys'][$link['key']] = true;
            $cluster['kinds'][$link['kind']->value] = ($cluster['kinds'][$link['kind']->value] ?? 0) + 1;
            if ($link['name'] !== null) {
                $cluster['names'][$link['name']] = ($cluster['names'][$link['name']] ?? 0) + 1;
            }
            $cluster['urls'][$link['link_type']][$link['url']] = ($cluster['urls'][$link['link_type']][$link['url']] ?? 0) + 1;
            $cluster['articles'][($link['article'] instanceof Post ? 'post:' : 'page:') . $link['article']->id] = $link['article'];
            unset($cluster);
        }

        $result = [];
        foreach ($clusters as $cluster) {
            arsort($cluster['kinds']);
            $names = $cluster['names'] ?? [];
            arsort($names);
            $urls = array_map(function (array $counts) {
                arsort($counts);

                return array_keys($counts);
            }, $cluster['urls']);
            $materialId = collect(array_keys($cluster['keys']))->map(fn ($key) => $keyMap[$key] ?? null)->filter()->first();

            $result[] = [
                'keys'          => array_keys($cluster['keys']),
                'kind'          => MaterialKind::from((string) array_key_first($cluster['kinds'])),
                'name'          => (string) (array_key_first($names) ?? ''),
                'names'         => $names,
                'amazon_url'    => $urls['amazon'][0] ?? null,
                'rakuten_url'   => $urls['rakuten'][0] ?? null,
                'affiliate_url' => $urls['affiliate'][0] ?? null,
                // 同じ教材の、よく使われているもの以外のリンク
                'extra_urls'    => array_values(array_merge(array_slice($urls['amazon'] ?? [], 1), array_slice($urls['rakuten'] ?? [], 1), array_slice($urls['affiliate'] ?? [], 1))),
                'articles'      => array_values($cluster['articles']),
                'material'      => $materialId !== null ? $materials->get($materialId) : null,
            ];
        }

        // 使われている記事が多い順
        usort($result, fn ($a, $b) => [$a['kind']->value, -count($a['articles'])] <=> [$b['kind']->value, -count($b['articles'])]);

        return $result;
    }

    /**
     * 記事の本文にある教材を記録し直す（同期で本文を取り込んだとき）
     */
    public function syncArticle(Post|Page $article): void
    {
        $keyMap = $this->keyMap($article->blog_id);
        if ($keyMap === [] && ! $this->hasRecords($article)) {
            return;
        }

        $materialIds = [];
        foreach ($this->scanner->keys((string) $article->content_raw) as $key) {
            if (isset($keyMap[$key])) {
                $materialIds[] = $keyMap[$key];
            }
        }

        $this->materials->replaceDetected($article, $materialIds);
    }

    /**
     * ブログの全記事について記録し直す（教材を登録・更新したとき）
     *
     * @return int 教材を使っている記事の数
     */
    public function syncBlog(Blog $blog): int
    {
        $this->forget($blog->id);
        $count = 0;

        foreach ([Post::class, Page::class] as $modelClass) {
            $modelClass::where('blog_id', $blog->id)->existing()->chunkById(100, function ($articles) use (&$count) {
                foreach ($articles as $article) {
                    $this->syncArticle($article);
                    $count += $this->hasRecords($article) ? 1 : 0;
                }
            });
        }

        return $count;
    }

    /**
     * 教材を登録・更新した後に、照合に使う識別子を読み直す
     */
    public function forget(int $blogId): void
    {
        unset($this->keyMaps[$blogId]);
    }

    /**
     * @return array<string, int>
     */
    protected function keyMap(int $blogId): array
    {
        if (! isset($this->keyMaps[$blogId])) {
            $map = [];
            foreach ($this->materials->allForBlog($blogId) as $material) {
                foreach ($material->linkKeys() as $key) {
                    $map[$key] ??= $material->id;
                }
            }
            $this->keyMaps[$blogId] = $map;
        }

        return $this->keyMaps[$blogId];
    }

    protected function hasRecords(Post|Page $article): bool
    {
        return $this->materials->forArticle($article)->isNotEmpty();
    }
}
