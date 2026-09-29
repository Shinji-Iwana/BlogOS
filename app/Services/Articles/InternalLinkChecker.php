<?php

namespace App\Services\Articles;

use App\Models\Blog;
use App\Models\Category;
use App\Models\InternalLink;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * ブログ全体の内部リンクの確認（D-42）。同期で本文から取り出した内部リンク（internal_links）から判定する（WordPress・外部にはアクセスしない）。
 *
 * - リンク切れ：ない URL、公開していない（削除した）記事へのリンク
 * - 古い URL：記事はあるが、URL が今の URL と違う（カテゴリの変更など。WordPress の転送で開けている）→ 機械的に直す
 * - カテゴリの一覧へのリンク：そのカテゴリのロードマップのページがあれば、そちらへのリンクにする → 機械的に直す
 * - 孤立記事：公開中のほかの記事から、1つもリンクされていない記事（投稿とロードマップのページ）
 * - ロードマップに載っていない記事：カテゴリの子ロードマップのページから、リンクされていない投稿
 * - 内部リンクがない記事：本文に内部リンクが1つもない投稿（参考）
 *
 * ロードマップのページは、カテゴリの立ち上げ（D-41）・テーマのパンくずと同じ決まりで探す：親カテゴリは、スラッグが同じで親のページがない
 * 固定ページ、子カテゴリは、親ロードマップの子のページで、スラッグが同じもの。
 */
class InternalLinkChecker
{
    public const KINDS = [
        'broken'         => 'リンク切れ',
        'outdated'       => '古い URL',
        'category'       => 'カテゴリの一覧へのリンク',
        'orphan'         => '孤立記事',
        'not_in_roadmap' => 'ロードマップに載っていない記事',
        'no_outbound'    => '内部リンクがない記事',
    ];

    /**
     * リンクの問題として扱わない、記事以外のページ（トップ・タグ・投稿者・ページ送りなど）
     */
    protected const IGNORED_PATHS = '#^/(tag|author|page|search|date)(/|$)#';

    /**
     * @var array<int, array<string, mixed>> ブログごとの確認の結果
     */
    protected array $results = [];

    /**
     * @return array{
     *     links: list<array{kind: string, link: InternalLink, source: Post|Page, target: Post|Page|null, fix: ?string, reason: string}>,
     *     articles: list<array{kind: string, article: Post|Page, reason: string}>
     * }
     */
    public function check(Blog $blog): array
    {
        return $this->results[$blog->id] ??= $this->run($blog);
    }

    public function forget(int $blogId): void
    {
        unset($this->results[$blogId]);
    }

    /**
     * 種類ごとの件数
     *
     * @return array<string, int>
     */
    public function counts(Blog $blog): array
    {
        $result = $this->check($blog);
        $counts = array_fill_keys(array_keys(self::KINDS), 0);
        foreach (array_merge($result['links'], $result['articles']) as $row) {
            $counts[$row['kind']]++;
        }

        return $counts;
    }

    /**
     * この記事から出ているリンクの問題（記事改修の指示文・機械的な修正に使う）
     *
     * @return list<array{kind: string, link: InternalLink, source: Post|Page, target: Post|Page|null, fix: ?string, reason: string}>
     */
    public function linkIssuesFor(Post|Page $article): array
    {
        $blog = $article->blog()->first();
        $column = $article instanceof Post ? 'post_id' : 'page_id';

        return array_values(array_filter($this->check($blog)['links'], fn ($row) => $row['link']->{$column} === $article->id));
    }

    /**
     * 機械的に直せるリンク（古い URL・カテゴリの一覧 → ロードマップ）を直した本文
     *
     * @return array{content: string, notes: list<string>}
     */
    public function fixContent(Post|Page $article, string $content): array
    {
        $notes = [];
        foreach ($this->linkIssuesFor($article) as $row) {
            if ($row['fix'] === null) {
                continue;
            }
            $from = $row['link']->target_url;
            $replaced = preg_replace_callback('/(href\s*=\s*)(["\'])(.*?)\2/i', function ($m) use ($from, $row) {
                return html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5) === $from ? $m[1] . $m[2] . e($row['fix']) . $m[2] : $m[0];
            }, $content);
            if ($replaced !== null && $replaced !== $content) {
                $content = $replaced;
                $notes[] = "{$row['reason']}：{$from} → {$row['fix']}";
            }
        }

        return ['content' => $content, 'notes' => array_values(array_unique($notes))];
    }

    /**
     * 記事改修の指示文に入れる、この記事の内部リンクの問題（D-42）
     */
    public function describeFor(Post|Page $article): string
    {
        $lines = [];
        foreach ($this->linkIssuesFor($article) as $row) {
            $anchor = $row['link']->anchor_text ? "「{$row['link']->anchor_text}」" : '（リンクの文字なし）';
            $lines[] = "- {$anchor} → {$row['link']->target_url}：{$row['reason']}"
                . ($row['fix'] !== null ? '（BlogOS が直します。目印にしてかまいません）' : '（内容に合う記事の目印に置き換えるか、合う記事がなければリンクを外してください）');
        }

        return $lines === [] ? '（なし）' : implode("\n", array_values(array_unique($lines)));
    }

    /**
     * どこからもリンクされていない記事（記事の一覧の印に使う）
     *
     * @return array{posts: array<int, true>, pages: array<int, true>}
     */
    public function orphanIds(Blog $blog): array
    {
        $ids = ['posts' => [], 'pages' => []];
        foreach ($this->check($blog)['articles'] as $row) {
            if ($row['kind'] === 'orphan' || $row['kind'] === 'not_in_roadmap') {
                $ids[$row['article'] instanceof Post ? 'posts' : 'pages'][$row['article']->id] = true;
            }
        }

        return $ids;
    }

    /**
     * カテゴリのロードマップのページ（公開中のもの。なければ null）
     */
    public function roadmapPage(Category $category): ?Page
    {
        $pages = $this->pages($category->blog_id);
        if ($category->parent_id === null) {
            return $pages->first(fn (Page $page) => $page->slug === $category->slug && (int) $page->wordpress_parent_id === 0);
        }

        $parent = Category::find($category->parent_id);
        $parentPage = $parent !== null ? $this->roadmapPage($parent) : null;

        return $parentPage === null ? null
            : $pages->first(fn (Page $page) => $page->slug === $category->slug && (int) $page->wordpress_parent_id === (int) $parentPage->wordpress_id);
    }

    protected function run(Blog $blog): array
    {
        $this->pageCache = [];
        $posts = Post::where('blog_id', $blog->id)->existing()->with('categories:id,blog_id,name,slug,parent_id')
            ->get(['id', 'blog_id', 'wordpress_id', 'title_raw', 'status', 'link'])->keyBy('id');
        $pages = Page::where('blog_id', $blog->id)->existing()->get(['id', 'blog_id', 'wordpress_id', 'wordpress_parent_id', 'title_raw', 'slug', 'status', 'link'])->keyBy('id');
        $categories = Category::where('blog_id', $blog->id)->existing()->get(['id', 'blog_id', 'name', 'slug', 'parent_id']);
        $links = InternalLink::where('blog_id', $blog->id)->orderBy('id')->get();

        $linkRows = [];
        $inbound = ['posts' => [], 'pages' => []];
        $outbound = [];
        foreach ($links as $link) {
            $source = $link->post_id !== null ? $posts->get($link->post_id) : $pages->get($link->page_id);
            if ($source === null) {
                continue;
            }
            $target = $link->target_post_id !== null ? ($posts->get($link->target_post_id) ?? Post::find($link->target_post_id))
                : ($link->target_page_id !== null ? ($pages->get($link->target_page_id) ?? Page::find($link->target_page_id)) : null);
            $self = $target !== null && $target::class === $source::class && $target->id === $source->id;
            if (! $self) {
                $outbound[$source::class . ':' . $source->id] = true;
            }
            if ($target !== null && ! $self && $source->status === 'publish') {
                $inbound[$target instanceof Post ? 'posts' : 'pages'][$target->id][$source::class . ':' . $source->id] = true;
            }

            $row = $this->classify($link, $target, $categories);
            if ($row !== null) {
                $linkRows[] = $row + ['link' => $link, 'source' => $source, 'target' => $target];
            }
        }

        $articleRows = [];
        $published = $posts->where('status', 'publish');
        $roadmapPages = $pages->filter(fn (Page $page) => $page->status === 'publish' && $this->isRoadmap($page, $categories));
        foreach ($published as $post) {
            if (empty($inbound['posts'][$post->id])) {
                $articleRows[] = ['kind' => 'orphan', 'article' => $post, 'reason' => '公開中のほかの記事から、リンクされていません'];
            }
            $category = $post->categories->first();
            $roadmap = $category !== null ? $this->roadmapPage($category) : null;
            if ($roadmap !== null && empty($inbound['posts'][$post->id][Page::class . ':' . $roadmap->id])) {
                $articleRows[] = ['kind' => 'not_in_roadmap', 'article' => $post, 'reason' => "ロードマップ「{$roadmap->title_raw}」に載っていません"];
            }
            if (empty($outbound[Post::class . ':' . $post->id])) {
                $articleRows[] = ['kind' => 'no_outbound', 'article' => $post, 'reason' => '本文に内部リンクがありません'];
            }
        }
        foreach ($roadmapPages as $page) {
            if (empty($inbound['pages'][$page->id])) {
                $articleRows[] = ['kind' => 'orphan', 'article' => $page, 'reason' => '公開中のほかの記事から、リンクされていません（ロードマップのページ）'];
            }
        }

        return ['links' => $linkRows, 'articles' => $articleRows];
    }

    /**
     * @param Collection<int, Category> $categories
     * @return array{kind: string, fix: ?string, reason: string}|null 問題がなければ null
     */
    protected function classify(InternalLink $link, Post|Page|null $target, Collection $categories): ?array
    {
        $path = (string) parse_url($link->target_url, PHP_URL_PATH);

        if ($target !== null) {
            if ($target->wordpress_deleted_at !== null || $target->status !== 'publish') {
                return ['kind' => 'broken', 'fix' => null, 'reason' => "公開していない記事「{$target->title_raw}」へのリンクです"];
            }
            $current = (string) parse_url((string) $target->link, PHP_URL_PATH);
            if ($current !== '' && rtrim($path, '/') !== rtrim($current, '/')) {
                return ['kind' => 'outdated', 'fix' => $this->sameStyle($link->target_url, (string) $target->link), 'reason' => "記事「{$target->title_raw}」の古い URL です"];
            }

            return null;
        }

        if (trim($path, '/') === '' || preg_match(self::IGNORED_PATHS, $path)) {
            return null;
        }

        if (preg_match('#^/category/(.+?)/?$#', $path, $m)) {
            $slugs = explode('/', trim($m[1], '/'));
            $category = $categories->firstWhere('slug', end($slugs));
            if ($category === null) {
                return ['kind' => 'broken', 'fix' => null, 'reason' => 'ないカテゴリの一覧へのリンクです'];
            }
            $roadmap = $this->roadmapPage($category);

            return $roadmap !== null
                ? ['kind' => 'category', 'fix' => $this->sameStyle($link->target_url, (string) $roadmap->link), 'reason' => "カテゴリ「{$category->name}」の一覧へのリンクです。ロードマップ「{$roadmap->title_raw}」にします"]
                : null;
        }

        return ['kind' => 'broken', 'fix' => null, 'reason' => 'この URL の記事がありません'];
    }

    /**
     * 直した URL を、元のリンクと同じ書き方（ホストを含むか、パスだけか）にする
     */
    protected function sameStyle(string $original, string $url): string
    {
        if (str_starts_with($original, '/') && ! str_starts_with($original, '//')) {
            $parts = parse_url($url);

            return ($parts['path'] ?? '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
        }

        return $url;
    }

    /**
     * @param Collection<int, Category> $categories
     */
    protected function isRoadmap(Page $page, Collection $categories): bool
    {
        $category = $categories->firstWhere('slug', $page->slug);

        return $category !== null && $this->roadmapPage($category)?->id === $page->id;
    }

    /**
     * @var array<int, Collection<int, Page>>
     */
    protected array $pageCache = [];

    /**
     * @return Collection<int, Page>
     */
    protected function pages(int $blogId): Collection
    {
        return $this->pageCache[$blogId] ??= Page::where('blog_id', $blogId)->existing()->where('status', 'publish')
            ->get(['id', 'blog_id', 'wordpress_id', 'wordpress_parent_id', 'title_raw', 'slug', 'status', 'link']);
    }
}
