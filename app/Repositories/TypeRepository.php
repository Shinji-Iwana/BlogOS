<?php

namespace App\Repositories;

use App\DTO\WordPress\TypeApiDto;
use App\Models\Type;
use App\Models\TypeHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TypeRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * name：投稿タイプ名：投稿
     * slug：投稿タイプ識別子：post
     * description：説明：投稿
     * hierarchical：階層構造か：false
     * rest_base：REST APIベース：posts
     * rest_namespace：REST namespace：wp/v2
     * supports：対応機能：{"title":true,"editor":true,...}
     * has_archive：アーカイブを持つか：true
     * taxonomies：利用する分類：["category","post_tag"]
     * visibility：公開設定：{...}
     * icon：アイコン：dashicons-admin-post
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Type::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Type
    {
        return Type::find($id);
    }
}
