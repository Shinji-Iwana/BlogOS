<?php

namespace App\Services\Materials;

use App\Enums\MaterialKind;
use App\Models\Category;
use App\Models\Material;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * カテゴリごとの教材のそろい具合（D-45）。
 *
 * 記事の改修・新規記事作成では、記事のカテゴリとその親カテゴリに登録した教材のうち、情報を調べてあり、提携中のリンクがある
 * 有効な教材が候補になる（MaterialMatcher）。ここでは同じ条件で、カテゴリの記事で紹介に使える教材を数える。
 */
class MaterialCoverageService
{
    public function __construct(
        protected AffiliateProgramService $programs,
    ) {
    }

    /**
     * 記事で紹介に使えない理由（使えるなら空）
     *
     * @return list<string>
     */
    public function problems(Material $material): array
    {
        $problems = [];
        if (! $material->isActive()) {
            $problems[] = "状態が「{$material->status->label()}」";
        }
        if (blank($material->summary)) {
            $problems[] = '情報を調べていない（「AIで調べる」）';
        }
        foreach ($this->programs->problems($material) as $problem) {
            $problems[] = "提携中でない：{$problem}";
        }

        return $problems;
    }

    /**
     * カテゴリと、その親カテゴリの ID（記事で候補になる教材の範囲）
     *
     * @param Collection<int, Category> $categories
     * @return list<int>
     */
    public function withAncestors(int $categoryId, Collection $categories): array
    {
        $ids = [];
        $current = $categories->firstWhere('id', $categoryId);
        while ($current !== null && ! in_array($current->id, $ids, true)) {
            $ids[] = (int) $current->id;
            $current = $current->parent_id !== null ? $categories->firstWhere('id', $current->parent_id) : null;
        }

        return $ids;
    }

    /**
     * カテゴリごとの、公開中の記事の数と、種類ごとの紹介に使える教材の数（own：このカテゴリに登録、inherited：親カテゴリに登録）
     *
     * @param Collection<int, Material> $materials
     * @param Collection<int, Category> $categories
     * @return array<int, array{posts: int, own: array<string, int>, inherited: array<string, int>, unusable: int}>
     */
    public function coverage(int $blogId, Collection $materials, Collection $categories): array
    {
        $posts = DB::table('post_categories')->join('posts', 'posts.id', '=', 'post_categories.post_id')
            ->where('posts.blog_id', $blogId)->where('posts.status', 'publish')->whereNull('posts.wordpress_deleted_at')
            ->groupBy('post_categories.category_id')->selectRaw('post_categories.category_id, count(*) as c')->pluck('c', 'category_id');

        $usable = $materials->filter(fn (Material $material) => $this->problems($material) === [])->pluck('id')->flip();
        $empty = array_fill_keys(array_map(fn (MaterialKind $kind) => $kind->value, MaterialKind::cases()), 0);

        $rows = [];
        foreach ($categories as $category) {
            $scope = $this->withAncestors((int) $category->id, $categories);
            $row = ['posts' => (int) ($posts[$category->id] ?? 0), 'own' => $empty, 'inherited' => $empty, 'unusable' => 0];
            foreach ($materials as $material) {
                $ids = $material->categories->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (array_intersect($ids, $scope) === []) {
                    continue;
                }
                if (! $usable->has($material->id)) {
                    $row['unusable']++;

                    continue;
                }
                $row[in_array((int) $category->id, $ids, true) ? 'own' : 'inherited'][$material->kind->value]++;
            }
            $rows[$category->id] = $row;
        }

        return $rows;
    }
}
