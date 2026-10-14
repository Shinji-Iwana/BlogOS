<?php

namespace App\Services\Topics;

use App\Enums\KeywordType;
use App\Enums\SuggestionStatus;
use App\Models\ArticleDraft;
use App\Models\ArticleKeyword;
use App\Models\Blog;
use App\Models\Category;
use App\Models\CategoryLaunchChild;
use App\Models\Page;
use App\Models\Post;
use App\Models\TopicSuggestion;
use App\Services\Materials\MaterialMatcher;
use App\Support\QualityProfiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 記事の企画の指示文に入れる値（D-40）。PromptBuilder から使う。
 */
class TopicPlanningPromptValues
{
    public const UNITS = ['articles' => '子カテゴリの、まだ記事にしていない内容', 'categories' => '親カテゴリの、足りない子カテゴリ'];

    public function __construct(
        protected MaterialMatcher $matcher,
    ) {
    }

    /**
     * @param array<string, string|null> $parameters
     * @return array<string, string>
     */
    public function values(Blog $blog, array $parameters, bool $webSearch): array
    {
        $unit = ($parameters['企画の単位の値'] ?? 'articles') === 'categories' ? 'categories' : 'articles';
        $category = ctype_digit((string) ($parameters['カテゴリの値'] ?? '')) ? Category::where('blog_id', $blog->id)->find((int) $parameters['カテゴリの値']) : null;
        if ($category === null) {
            return ['planning_target' => '（カテゴリが指定されていません）', 'type_focus' => '（なし）'];
        }

        $all = Category::where('blog_id', $blog->id)->existing()->get(['id', 'name', 'slug', 'parent_id']);
        // 対象の範囲：子カテゴリの企画は、その親の下全体（重複を避けるため）。親カテゴリの企画は、その下全体
        $root = $unit === 'categories' ? $category : ($all->firstWhere('id', $category->parent_id) ?? $category);
        $scopeIds = $this->descendants($all, $root->id);
        $posts = $this->postsIn($blog, $scopeIds);

        // カテゴリの立ち上げの、WordPress にまだない子カテゴリ（D-41）。カテゴリの値は親カテゴリ
        $launchChild = ctype_digit((string) ($parameters['立ち上げの子の値'] ?? '')) ? CategoryLaunchChild::find((int) $parameters['立ち上げの子の値']) : null;
        $target = $launchChild !== null && $launchChild->isNew()
            ? "- 企画すること：新しい子カテゴリの、最初に書く記事\n- 親カテゴリ：{$category->name}\n- 新しい子カテゴリ：{$launchChild->name}（スラッグ：{$launchChild->slug}）"
                . ($launchChild->scope ? "\n- 範囲：{$launchChild->scope}" : '') . "\n- このカテゴリの記事の数：0件（まだ WordPress にカテゴリもありません）"
            : $this->target($unit, $category, $all, $posts);

        // 記事種類の指定（集客記事の細分類・収益記事。記事の案のときだけ。D-83-04）
        $kind = $unit === 'articles' && $launchChild === null ? $this->kind($blog, (string) ($parameters['記事種類の指定の値'] ?? '')) : null;
        $this->types = QualityProfiles::articleTypes($blog->quality_profile);

        return [
            'planning_target'   => $target,
            'type_focus'        => $this->typeFocus($blog, $kind, $category, $posts, $scopeIds),
            'category_tree'     => $this->tree($all, $root->id, $posts),
            'category_articles' => $this->articleList($all, $scopeIds, $posts, $category->id, $kind === 'revenue'),
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
     * 記事種類の名前（品質基準の記事種類と細分類。values() で読む）
     *
     * @var array{types: array<string, string>, subtypes: array<string, string>}
     */
    protected array $types = ['types' => [], 'subtypes' => []];

    /**
     * 記事の案で指定できる記事種類（値 => 名前）：集客記事の細分類と、収益記事（D-83-04）
     *
     * @return array<string, string>
     */
    public static function kinds(Blog $blog): array
    {
        $types = QualityProfiles::articleTypes($blog->quality_profile);
        $kinds = [];
        foreach ($types['subtypes'] as $subtype => $label) {
            $kinds["acquisition:{$subtype}"] = "集客記事（{$label}）";
        }
        if (isset($types['types']['revenue'])) {
            $kinds['revenue'] = $types['types']['revenue'];
        }

        return $kinds;
    }

    protected function kind(Blog $blog, string $value): ?string
    {
        return array_key_exists($value, self::kinds($blog)) ? $value : null;
    }

    /**
     * 記事種類の指定（指示文の「記事種類の指定」）。指定がなければ、AI が選ぶ。
     * 指定があれば、その記事種類だけを企画させ、対象のカテゴリの記事種類の内訳を渡す（少ない種類を狙うため）。
     * 収益記事は、比べる教材の候補（MaterialMatcher：提携中のリンクがあり、情報を調べた教材）・今ある収益記事と作業中の案を渡し、
     * 1つのテーマは1記事にさせる。比べる教材と、収益記事へのリンクを置く集客記事を、番号で出させる
     *
     * @param Collection<int, Collection<int, Post>> $posts
     * @param list<int> $scopeIds
     */
    protected function typeFocus(Blog $blog, ?string $kind, Category $category, Collection $posts, array $scopeIds): string
    {
        $inCategory = collect($posts[$category->id] ?? []);
        $breakdown = $inCategory->groupBy(fn (Post $post) => $this->typeLabel($post->article_type, $post->article_subtype) ?? '未登録')
            ->map(fn ($group, $label) => "{$label} " . $group->count() . '件')->implode('・');
        $lines = ['- 対象のカテゴリの記事種類の内訳：' . ($breakdown !== '' ? $breakdown : '（記事はまだありません）')];

        if ($kind === null) {
            return implode("\n", [...$lines, '- 指定：なし（記事種類は、読者に必要な内容に合わせて選んでください）']);
        }

        [$articleType, $subtype] = array_pad(explode(':', $kind, 2), 2, null);
        $label = self::kinds($blog)[$kind];
        $lines[] = "- 指定：{$label}（article_type は {$articleType}" . ($subtype ? "、article_subtype は {$subtype}" : '、article_subtype は null') . '）';
        $lines[] = "- 今回は、{$label}の案だけを出してください。ほかの記事種類の案は出さないでください。品質基準の記事種類の定義（article-types.md）とタイトルの型に沿ってください。";
        if ($articleType !== 'revenue') {
            return implode("\n", $lines);
        }

        // 収益記事：比べる教材の候補・今ある収益記事・作業中の案
        $candidates = $this->matcher->candidates($blog, null, ['category_ids' => $scopeIds, 'article_type' => 'revenue'], 20);
        $existing = Post::where('blog_id', $blog->id)->existing()
            ->whereExists(fn ($q) => $q->from('article_managements')->whereColumn('article_managements.post_id', 'posts.id')->where('article_managements.article_type', 'revenue'))
            ->pluck('title_raw');
        $planned = TopicSuggestion::where('blog_id', $blog->id)->where('type', 'article')->where('article_type', 'revenue')
            ->whereIn('status', [SuggestionStatus::Pending->value, SuggestionStatus::Accepted->value])->pluck('title');

        return implode("\n", [
            ...$lines,
            '- 収益記事は、読者が教材・サービスを選ぶための記事です（購入判断の支援。売るための記事ではありません）。ブログ全体で、1つのテーマにつき1記事にしてください。下の「今ある収益記事」「企画済みの収益記事」と同じテーマ・同じ検索意図の案や、それを言い換えた案は出さないでください。',
            '- 比べる教材は、下の「比べる教材の候補」から選び、教材IDを material_ids に書いてください（比べる教材が2つ未満になるテーマは、案にせず、summary に「先に登録・調査すると良い教材」を書いてください）。',
            '- 収益記事へのリンクを置くと良い集客記事を、「今ある記事」の記事の番号で link_from_post_ids に書いてください（読者が自然に教材を探す場面の記事。3〜8件）。',
            '- 人の実体験（使った感想など）がなくても、公式の情報などの根拠・客観的な比較・選び方の基準・デメリット・読者の状況ごとのおすすめで、読者が自分で選べる記事になるテーマにしてください。',
            '',
            '## 今ある収益記事',
            $existing->isEmpty() ? '（なし）' : $existing->map(fn ($title) => "- {$title}")->implode("\n"),
            '',
            '## 企画済みの収益記事（確認待ち・採用済みの案）',
            $planned->isEmpty() ? '（なし）' : $planned->map(fn ($title) => "- {$title}")->implode("\n"),
            '',
            '## 比べる教材の候補',
            MaterialMatcher::describe($candidates),
        ]);
    }

    /**
     * 記事種類の名前（例：集客記事（Do記事））。登録していなければ null
     */
    protected function typeLabel(?string $articleType, ?string $subtype): ?string
    {
        if (blank($articleType)) {
            return null;
        }
        $label = $this->types['types'][$articleType] ?? $articleType;

        return $subtype ? $label . '（' . ($this->types['subtypes'][$subtype] ?? $subtype) . '）' : $label;
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
        // 記事種類も読む（今ある記事の一覧と、記事種類の内訳に出す。D-83-04）
        $posts = Post::where('posts.blog_id', $blog->id)->existing()->whereIn('posts.id', $rows->pluck('post_id')->unique())
            ->leftJoin('article_managements as m', 'm.post_id', '=', 'posts.id')
            ->get(['posts.id', 'posts.title_raw', 'posts.status', 'm.article_type', 'm.article_subtype'])->keyBy('id');

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
    protected function articleList(Collection $all, array $scopeIds, Collection $posts, int $targetId, bool $withIds = false): string
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
                // 記事種類を先頭に出す。収益記事の企画では、リンクを置く集客記事を番号で選ばせるため、記事の番号も出す（D-83-04）
                $type = $this->typeLabel($post->article_type, $post->article_subtype);
                $lines[] = "- " . ($withIds ? "［記事の番号 {$post->id}］" : '') . ($type !== null ? "［{$type}］" : '') . $post->title_raw
                    . (isset($keywords[$post->id]) ? "（メインキーワード：{$keywords[$post->id]}）" : '') . ($post->status !== 'publish' ? '（未公開）' : '');
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
