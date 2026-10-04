<?php

namespace App\Services\Terms;

use App\Models\Blog;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * カテゴリの一覧（親子の順）と、スラッグを変えたときの影響（D-53）。
 *
 * si-note の記事の URL は「/親カテゴリのスラッグ/子カテゴリのスラッグ/記事のID.html」のため、
 * カテゴリのスラッグを変えると、カテゴリのページと、そのカテゴリ・子カテゴリの全記事の URL が変わる。
 */
class CategoryTreeService
{
    /**
     * 親子の順に並べたカテゴリ（WordPress で削除されたものを除く）。各カテゴリに depth（深さ）・posts_count（記事の数）を付ける
     *
     * @return Collection<int, Category>
     */
    public function tree(Blog $blog): Collection
    {
        $categories = Category::where('blog_id', $blog->id)->existing()
            ->withCount(['posts' => fn ($query) => $query->whereNull('posts.wordpress_deleted_at')])
            ->orderBy('name')
            ->get();

        $children = $categories->groupBy(fn (Category $category) => $category->parent_id ?? 0);
        $ids = $categories->pluck('id')->all();
        $ordered = collect();

        $walk = function (int $parentId, int $depth) use (&$walk, $children, $ordered): void {
            foreach ($children->get($parentId, collect()) as $category) {
                $category->depth = $depth;
                $ordered->push($category);
                $walk($category->id, $depth + 1);
            }
        };
        $walk(0, 0);

        // 親が削除されたカテゴリなど、どこにもつながらないものは最後に
        foreach ($categories as $category) {
            if (! $ordered->contains('id', $category->id) && ! in_array($category->parent_id, $ids, true)) {
                $category->depth = 0;
                $ordered->push($category);
                $walk($category->id, 1);
            }
        }

        return $ordered;
    }

    /**
     * スラッグを変えたときに URL が変わる記事（このカテゴリと子孫のカテゴリの記事。重複は1件）
     *
     * @return array{count: int, example: Post|null}
     */
    public function slugImpact(Category $category): array
    {
        $ids = $this->descendantIds($category);
        $postIds = DB::table('post_categories')->whereIn('category_id', $ids)->distinct()->pluck('post_id');
        $posts = Post::whereIn('id', $postIds)->whereNull('wordpress_deleted_at');

        return [
            'count'   => (clone $posts)->count(),
            'example' => (clone $posts)->where('status', 'publish')->orderBy('id')->first(['id', 'link', 'title_raw']),
        ];
    }

    /**
     * このカテゴリと、子孫のカテゴリの ID
     *
     * @return list<int>
     */
    public function descendantIds(Category $category): array
    {
        $all = Category::where('blog_id', $category->blog_id)->existing()->get(['id', 'parent_id']);
        $ids = [$category->id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($all->where('parent_id', $ids[$i]) as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }
}
