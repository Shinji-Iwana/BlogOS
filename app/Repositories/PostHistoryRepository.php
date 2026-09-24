<?php

namespace App\Repositories;

use App\Models\PostHistory;
use Illuminate\Database\Eloquent\Collection;

class PostHistoryRepository
{

    public const FIELD_LABELS = [
        'blog_id'   => 'ブログID',
        'post_id'   => '記事ID',
        'field'     => '項目',
        'old_value' => '変更前',
        'new_value' => '変更後',
        'source'    => '経路',
    ];

    public function getAll(int $blogId): Collection
    {
        return PostHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?PostHistory
    {
        return PostHistory::find($id);
    }
}
