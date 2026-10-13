<?php

namespace App\Services\Topics;

use App\Models\Blog;
use App\Models\Category;
use App\Services\Terms\CategoryTreeService;
use App\Support\QualityProfiles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * カテゴリごと × 記事種類の記事の数（記事の企画の画面。D-83-01）。
 *
 * 公開中の記事を数える。投稿はカテゴリ（post_categories）で、ロードマップの固定ページはカテゴリと同じスラッグで結び付ける
 * （親ロードマップは親カテゴリのスラッグ、子ロードマップは子カテゴリのスラッグ。CategoryLaunchPublishService と同じ）。
 * 2つのカテゴリに属する投稿は、それぞれで数える。親カテゴリの行は、自分と子孫のカテゴリの記事を重ねずに数えた小計。
 */
class ArticleTypeMatrixService
{
    /**
     * 収益記事の割合の目安（全体。超えたら注意を出す。止めはしない。D-83-01）
     */
    public const REVENUE_RATIO_GUIDE = 0.1;

    public function __construct(
        protected CategoryTreeService $tree,
    ) {
    }

    /**
     * 列（キー => 見出し）。集客記事は細分類ごとに分け、細分類のない集客記事は「集客（細分類なし）」
     *
     * @return array<string, string>
     */
    public function columns(Blog $blog): array
    {
        $types = QualityProfiles::articleTypes($blog->quality_profile);
        $columns = [];
        foreach ($types['types'] as $type => $label) {
            if ($type === 'acquisition') {
                foreach ($types['subtypes'] as $subtype => $subtypeLabel) {
                    $columns["acquisition:{$subtype}"] = "集客（{$subtypeLabel}）";
                }
                $columns['acquisition:'] = '集客（細分類なし）';
            } else {
                $columns[$type] = $label;
            }
        }
        $columns['unregistered'] = '未登録';

        return $columns;
    }

    /**
     * カテゴリごとの行（親子の順）と、全体の合計
     *
     * @return array{columns: array<string, string>, rows: list<array{category: Category, depth: int, counts: array<string, int>, total: int, revenue_ratio: float|null}>, totals: array<string, int>, total: int, revenue_ratio: float|null, revenue_over: bool}
     */
    public function build(Blog $blog): array
    {
        $columns = $this->columns($blog);
        $categories = $this->tree->tree($blog);

        // 公開中の投稿：記事 × カテゴリ × 列のキー
        $postRows = DB::table('posts')
            ->join('post_categories', 'post_categories.post_id', '=', 'posts.id')
            ->leftJoin('article_managements as m', 'm.post_id', '=', 'posts.id')
            ->where('posts.blog_id', $blog->id)->where('posts.status', 'publish')->whereNull('posts.wordpress_deleted_at')
            ->get(['posts.id', 'post_categories.category_id', 'm.article_type', 'm.article_subtype']);

        // 公開中のロードマップの固定ページ：カテゴリと同じスラッグで結び付ける
        $slugs = $categories->pluck('id', 'slug');
        $pageRows = DB::table('pages')
            ->join('article_managements as m', 'm.page_id', '=', 'pages.id')
            ->where('pages.blog_id', $blog->id)->where('pages.status', 'publish')->whereNull('pages.wordpress_deleted_at')
            ->whereIn('m.article_type', ['parent_roadmap', 'child_roadmap'])
            ->get(['pages.id', 'pages.slug', 'm.article_type', 'm.article_subtype'])
            ->filter(fn ($page) => isset($slugs[$page->slug]))
            ->map(fn ($page) => (object) ['id' => 'page-' . $page->id, 'category_id' => $slugs[$page->slug], 'article_type' => $page->article_type, 'article_subtype' => $page->article_subtype]);

        $records = $postRows->map(fn ($row) => (object) ['id' => 'post-' . $row->id, 'category_id' => $row->category_id, 'key' => $this->key($row, $columns)])
            ->concat($pageRows->map(fn ($row) => (object) ['id' => $row->id, 'category_id' => $row->category_id, 'key' => $this->key($row, $columns)]));
        $byCategory = $records->groupBy('category_id');

        // 子孫のカテゴリの ID（親の行の小計のため）
        $children = $categories->groupBy(fn (Category $category) => $category->parent_id ?? 0);
        $descendants = function (int $id) use (&$descendants, $children): array {
            return $children->get($id, collect())->flatMap(fn (Category $child) => [$child->id, ...$descendants($child->id)])->all();
        };

        $rows = $categories->map(function (Category $category) use ($byCategory, $descendants, $columns) {
            // 自分と子孫の記事（同じ記事は1回だけ数える）
            $articles = collect([$category->id, ...$descendants($category->id)])
                ->flatMap(fn ($id) => $byCategory->get($id, collect()))
                ->unique('id');

            return $this->row($articles, $columns) + ['category' => $category, 'depth' => (int) $category->depth];
        })->values()->all();

        $all = $records->unique('id');
        $summary = $this->row($all, $columns);

        return [
            'columns'       => $columns,
            'rows'          => $rows,
            'totals'        => $summary['counts'],
            'total'         => $summary['total'],
            'revenue_ratio' => $summary['revenue_ratio'],
            'revenue_over'  => ($summary['revenue_ratio'] ?? 0) > self::REVENUE_RATIO_GUIDE,
        ];
    }

    /**
     * 記事の列のキー（記事種類・細分類。登録していない・品質基準にない記事種類は unregistered。品質基準にない細分類の集客記事は、細分類なし）
     */
    protected function key(object $row, array $columns): string
    {
        if ($row->article_type === null || $row->article_type === '') {
            return 'unregistered';
        }
        if ($row->article_type === 'acquisition') {
            $key = 'acquisition:' . ($row->article_subtype ?? '');

            return isset($columns[$key]) ? $key : 'acquisition:';
        }

        return isset($columns[$row->article_type]) ? $row->article_type : 'unregistered';
    }

    /**
     * 記事の集まりから、列ごとの数・合計・収益記事の割合
     *
     * @return array{counts: array<string, int>, total: int, revenue_ratio: float|null}
     */
    protected function row(Collection $articles, array $columns): array
    {
        $counts = array_fill_keys(array_keys($columns), 0);
        foreach ($articles as $article) {
            $counts[$article->key]++;
        }
        $total = array_sum($counts);

        return ['counts' => $counts, 'total' => $total, 'revenue_ratio' => $total > 0 && isset($counts['revenue']) ? $counts['revenue'] / $total : null];
    }
}
