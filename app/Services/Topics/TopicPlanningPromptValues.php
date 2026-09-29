<?php

namespace App\Services\Topics;

use App\Enums\KeywordType;
use App\Models\ArticleDraft;
use App\Models\ArticleKeyword;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 記事の企画の指示文に入れる値（D-40）。PromptBuilder から使う。
 */
class TopicPlanningPromptValues
{
    public const UNITS = ['articles' => '子カテゴリの、まだ記事にしていない内容', 'categories' => '親カテゴリの、足りない子カテゴリ'];

    /**
     * @param array<string, string|null> $parameters
     * @return array<string, string>
     */
    public function values(Blog $blog, array $parameters, bool $webSearch): array
    {
        $unit = ($parameters['企画の単位の値'] ?? 'articles') === 'categories' ? 'categories' : 'articles';
        $category = ctype_digit((string) ($parameters['カテゴリの値'] ?? '')) ? Category::where('blog_id', $blog->id)->find((int) $parameters['カテゴリの値']) : null;
        if ($category === null) {
            return ['planning_target' => '（カテゴリが指定されていません）'];
        }

        $all = Category::where('blog_id', $blog->id)->existing()->get(['id', 'name', 'slug', 'parent_id']);
        // 対象の範囲：子カテゴリの企画は、その親の下全体（重複を避けるため）。親カテゴリの企画は、その下全体
        $root = $unit === 'categories' ? $category : ($all->firstWhere('id', $category->parent_id) ?? $category);
        $scopeIds = $this->descendants($all, $root->id);
        $posts = $this->postsIn($blog, $scopeIds);

        return [
            'planning_target'   => $this->target($unit, $category, $all, $posts),
            'category_tree'     => $this->tree($all, $root->id, $posts),
            'category_articles' => $this->articleList($all, $scopeIds, $posts, $category->id),
            'roadmap_info'      => $this->roadmap($blog, $category),
            // 検索語句：子カテゴリの企画は、そのカテゴリの記事の語句だけ（ほかのカテゴリの語句を混ぜない）
            'search_queries'    => $this->queries($blog, ($unit === 'articles' ? collect($posts[$category->id] ?? []) : $posts->flatten())->pluck('id')->unique()->values()->all()),
            'planned_drafts'    => $this->plannedDrafts($blog),
            'research_method'   => $webSearch
                ? 'Web検索を使えます。公式の資料（例：MDN、AWS のドキュメント、Microsoft のサポート）の目次や、ほかの入門サイトが扱っている範囲を調べ、このブログで抜けている内容を見つけてください。根拠にしたページの URL を sources に書いてください。'
                : 'Web検索は使えません。あなたの知識と、下の情報から考えてください。確かでない内容は案に入れないでください。',
        ];
    }

    /**
     * @param Collection<int, Category> $all
     * @return list<int>
     */
    protected function descendants(Collection $all, int $id): array
    {
        $ids = [$id];
        $current = [$id];
        while ($current !== []) {
            $current = $all->whereIn('parent_id', $current)->pluck('id')->all();
            $ids = array_merge($ids, $current);
        }

        return $ids;
    }

    /**
     * @param list<int> $categoryIds
     * @return Collection<int, Collection<int, Post>> カテゴリID => 公開中・下書きの投稿
     */
    protected function postsIn(Blog $blog, array $categoryIds): Collection
    {
        $rows = DB::table('post_categories')->whereIn('category_id', $categoryIds)->get(['post_id', 'category_id']);
        $posts = Post::where('blog_id', $blog->id)->existing()->whereIn('id', $rows->pluck('post_id')->unique())->get(['id', 'title_raw', 'status'])->keyBy('id');

        return $rows->groupBy('category_id')->map(fn ($group) => $group->map(fn ($row) => $posts[$row->post_id] ?? null)->filter()->values());
    }

    protected function target(string $unit, Category $category, Collection $all, Collection $posts): string
    {
        $parent = $all->firstWhere('id', $category->parent_id);
        $path = ($parent ? "{$parent->name} ＞ " : '') . $category->name;

        return "- 企画すること：" . self::UNITS[$unit] . "\n- 対象のカテゴリ：{$path}（スラッグ：{$category->slug}）"
            . ($unit === 'articles' ? "\n- このカテゴリの記事の数：" . count($posts[$category->id] ?? []) . '件' : '');
    }

    protected function tree(Collection $all, int $rootId, Collection $posts, int $depth = 0): string
    {
        $lines = [];
        $root = $all->firstWhere('id', $rootId);
        if ($depth === 0 && $root) {
            $lines[] = "- {$root->name}（{$root->slug}）：" . count($posts[$root->id] ?? []) . '記事';
        }
        foreach ($all->where('parent_id', $rootId)->sortBy('name') as $child) {
            $lines[] = str_repeat('  ', $depth + 1) . "- {$child->name}（{$child->slug}）：" . count($posts[$child->id] ?? []) . '記事';
            $sub = $this->tree($all, $child->id, $posts, $depth + 1);
            if ($sub !== '') {
                $lines[] = $sub;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<int> $scopeIds
     */
    protected function articleList(Collection $all, array $scopeIds, Collection $posts, int $targetId): string
    {
        $keywords = ArticleKeyword::whereIn('post_id', $posts->flatten()->pluck('id')->unique())->where('keyword_type', KeywordType::Main)->pluck('keyword', 'post_id');
        $lines = [];
        // 対象のカテゴリを先に、ほかのカテゴリは後に
        $ordered = array_merge([$targetId], array_values(array_diff($scopeIds, [$targetId])));
        foreach ($ordered as $categoryId) {
            $items = $posts[$categoryId] ?? collect();
            if ($items->isEmpty()) {
                continue;
            }
            $lines[] = '## ' . ($all->firstWhere('id', $categoryId)?->name ?? '') . ($categoryId === $targetId ? '（対象のカテゴリ）' : '');
            foreach ($items as $post) {
                $lines[] = "- {$post->title_raw}" . (isset($keywords[$post->id]) ? "（メインキーワード：{$keywords[$post->id]}）" : '') . ($post->status !== 'publish' ? '（未公開）' : '');
            }
        }

        return $lines === [] ? '（記事はまだありません）' : implode("\n", $lines);
    }

    /**
     * カテゴリのロードマップ（固定ページ。URL がスラッグ.html のもの）の学習のステップ
     */
    protected function roadmap(Blog $blog, Category $category): string
    {
        $page = Page::where('blog_id', $blog->id)->existing()->get(['id', 'title_raw', 'normalized_path', 'content_raw'])
            ->first(fn (Page $page) => preg_match('#(^|/)' . preg_quote((string) $category->slug, '#') . '\.html$#', (string) $page->normalized_path));
        if ($page === null) {
            return '（このカテゴリのロードマップはありません）';
        }

        preg_match_all('/<h3[^>]*>(.*?)<\/h3>/su', (string) $page->content_raw, $m);
        $steps = array_values(array_filter(array_map(fn ($h) => trim(strip_tags($h)), $m[1]), fn ($h) => str_starts_with($h, 'ステップ')));

        return "- ロードマップ：{$page->title_raw}\n" . ($steps === [] ? '- 学習のステップ：（見出しから読み取れません）' : "- 学習のステップ：\n" . implode("\n", array_map(fn ($s) => "  - {$s}", $steps)));
    }

    /**
     * @param list<int> $postIds
     */
    protected function queries(Blog $blog, array $postIds): string
    {
        $to = Carbon::parse(Carbon::now(config('blogos.display_timezone'))->subDays(2)->toDateString());
        $rows = DB::table('google_search_console_query_daily')->where('blog_id', $blog->id)->whereIn('post_id', $postIds)
            ->whereBetween('date', [$to->copy()->subDays(89)->toDateString(), $to->toDateString()])
            ->groupBy('query')->selectRaw('query, sum(impressions) as impressions, sum(clicks) as clicks, sum(position * impressions) / nullif(sum(impressions), 0) as position')
            ->orderByDesc('impressions')->limit(40)->get();

        return $rows->isEmpty() ? '（データがありません）'
            : "（検索語句：表示回数／クリック数／平均掲載順位。順位が低い・記事の内容と合っていない語句は、新しい記事の候補になる）\n"
                . $rows->map(fn ($row) => "- {$row->query}：{$row->impressions}／{$row->clicks}／" . round((float) $row->position, 1))->implode("\n");
    }

    protected function plannedDrafts(Blog $blog): string
    {
        $titles = ArticleDraft::where('blog_id', $blog->id)->whereNull('post_id')->whereNull('page_id')->active()->pluck('title_raw')->filter();

        return $titles->isEmpty() ? '（なし）' : $titles->map(fn ($title) => "- {$title}")->implode("\n");
    }
}
