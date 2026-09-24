<?php

namespace App\Repositories;

use App\Models\CategoryHistory;
use Illuminate\Database\Eloquent\Collection;

class CategoryHistoryRepository
{

    public const FIELD_LABELS = [
        'blog_id'     => 'ブログID',
        'category_id' => 'カテゴリID',
        'field'       => '項目',
        'old_value'   => '変更前',
        'new_value'   => '変更後',
        'source'      => '経路',
    ];

    public function getAll(int $blogId): Collection
    {
        return CategoryHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?CategoryHistory
    {
        return CategoryHistory::find($id);
    }
}
