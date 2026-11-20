<?php

namespace App\Services\Google;

use App\Models\ArticleMaterial;
use App\Models\Material;
use App\Support\AffiliateLink;
use Illuminate\Support\Facades\DB;

/**
 * 押された外部リンク（google_analytics_outbound_click_daily）が、どの教材のリンクかを決める（D-91-08）。
 *
 * 次の順に決め、決められない行は NULL のままにする（次の取り込みで、記事の教材が変わっていれば決め直す）。
 * 1. リンクの class にある教材の目印（blogos-m{教材ID}。BlogOS が [[教材:ID]] を置き換えるときに付ける）
 * 2. リンクの URL の識別子（AffiliateLink::key）が、記事で使っている教材のリンクと同じ
 * 3. 記事で使っている教材のうち、そのプログラム（もしもの広告など）のリンクを持つ教材が1件だけ
 *    （GA4 は URL を100文字で切るため、もしもの Amazon のリンクは商品の部分が残らない）
 * 4. リンクの URL の識別子が、ブログの教材のうち1件だけと同じ（記事の教材に登録されていないリンク）
 */
class OutboundClickMaterialResolver
{
    public const CLASS_PATTERN = '/(?:^|\s)blogos-m(\d+)(?:\s|$)/';

    /**
     * 教材のリンクに付ける class
     */
    public static function className(int $materialId): string
    {
        return "blogos-m{$materialId}";
    }

    /**
     * @return int 教材を決めた行数
     */
    public function resolve(int $blogId): int
    {
        $table = 'google_analytics_outbound_click_daily';

        $groups = DB::table($table)
            ->where('blog_id', $blogId)
            ->whereNull('material_id')
            ->where(fn ($query) => $query->whereNotNull('program')->orWhere('link_classes', 'like', '%blogos-m%'))
            ->groupBy('post_id', 'page_id', 'link_url_hash', 'link_url', 'link_classes', 'program')
            ->select('post_id', 'page_id', 'link_url_hash', 'link_url', 'link_classes', 'program')
            ->get();
        if ($groups->isEmpty()) {
            return 0;
        }

        $materials = Material::where('blog_id', $blogId)->get()->keyBy('id');
        $byArticle = ArticleMaterial::where('blog_id', $blogId)->get(['post_id', 'page_id', 'material_id'])
            ->groupBy(fn ($row) => $row->post_id !== null ? "post:{$row->post_id}" : "page:{$row->page_id}")
            ->map(fn ($rows) => $rows->pluck('material_id')->unique()->map(fn ($id) => $materials->get($id))->filter()->values());

        $resolved = 0;
        foreach ($groups as $group) {
            $articleKey = $group->post_id !== null ? "post:{$group->post_id}" : ($group->page_id !== null ? "page:{$group->page_id}" : null);
            $materialId = $this->decide($group, $articleKey !== null ? $byArticle->get($articleKey, collect()) : collect(), $materials);
            if ($materialId === null) {
                continue;
            }

            $resolved += DB::table($table)
                ->where('blog_id', $blogId)
                ->whereNull('material_id')
                ->where('post_id', $group->post_id)
                ->where('page_id', $group->page_id)
                ->where('link_url_hash', $group->link_url_hash)
                ->where('link_classes', $group->link_classes)
                ->update(['material_id' => $materialId]);
        }

        return $resolved;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Material> $articleMaterials
     * @param \Illuminate\Support\Collection<int, Material> $materials
     */
    protected function decide(object $group, $articleMaterials, $materials): ?int
    {
        // 1. class の目印
        if (preg_match(self::CLASS_PATTERN, (string) $group->link_classes, $matches)) {
            return $materials->has((int) $matches[1]) ? (int) $matches[1] : null;
        }

        // 2. 記事の教材とリンクが同じ
        $key = AffiliateLink::key((string) $group->link_url);
        if ($key !== null) {
            $same = $articleMaterials->filter(fn (Material $material) => in_array($key, $material->linkKeys(), true));
            if ($same->count() === 1) {
                return $same->first()->id;
            }
        }

        // 3. 記事の教材のうち、そのプログラムのリンクを持つのが1件だけ
        if ($group->program !== null) {
            $sameProgram = $articleMaterials->filter(fn (Material $material) => in_array($group->program, $material->programKeys(), true));
            if ($sameProgram->count() === 1) {
                return $sameProgram->first()->id;
            }
        }

        // 4. ブログの教材のうち、リンクが同じものが1件だけ
        if ($key !== null) {
            $same = $materials->filter(fn (Material $material) => in_array($key, $material->linkKeys(), true));
            if ($same->count() === 1) {
                return $same->first()->id;
            }
        }

        return null;
    }
}
