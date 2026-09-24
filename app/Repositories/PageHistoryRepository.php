<?php

namespace App\Repositories;

use App\Models\PageHistory;
use Illuminate\Database\Eloquent\Collection;

class PageHistoryRepository
{

    public const FIELD_LABELS = [
        'blog_id'   => 'ブログID',
        'page_id'   => '固定ページID',
        'field'     => '項目',
        'old_value' => '変更前',
        'new_value' => '変更後',
        'source'    => '経路',
    ];

    public function getAll(int $blogId): Collection
    {
        return PageHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?PageHistory
    {
        return PageHistory::find($id);
    }
}
