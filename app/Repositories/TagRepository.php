<?php

namespace App\Repositories;

use App\DTO\WordPress\TagApiDto;
use App\Models\Tag;
use App\Models\TagHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TagRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * id：タグID：25
     * count：使用されている公開記事数：8
     * description：タグ説明：AWS初心者向けの記事
     * link：タグURL：https://si-note.com/tag/aws/
     * name：タグ名：AWS
     * slug：タグスラッグ：aws
     * taxonomy：分類タイプ：post_tag
     * meta：メタ情報：{}
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Tag::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Tag
    {
        return Tag::find($id);
    }
}
