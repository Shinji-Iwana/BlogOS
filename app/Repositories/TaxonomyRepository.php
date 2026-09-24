<?php

namespace App\Repositories;

use App\DTO\WordPress\TaxonomyApiDto;
use App\Models\Taxonomy;
use App\Models\TaxonomyHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TaxonomyRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * name：分類名：カテゴリー
     * slug：分類スラッグ：category
     * description：説明：カテゴリー
     * hierarchical：階層構造を持つか：true
     * labels：各種表示ラベル：{...}
     * show_cloud：タグクラウドに表示するか：false
     * types：関連する投稿タイプ：["post"]
     * rest_base：REST APIのベース：categories
     * rest_namespace：REST namespace：wp/v2
     * visibility：公開設定：{...}
     * capabilities：操作権限：{...}
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Taxonomy::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Taxonomy
    {
        return Taxonomy::find($id);
    }
}
