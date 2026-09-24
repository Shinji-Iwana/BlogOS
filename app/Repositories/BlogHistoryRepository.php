<?php

namespace App\Repositories;

use App\Models\BlogHistory;
use Illuminate\Database\Eloquent\Collection;

class BlogHistoryRepository
{

    public const FIELD_LABELS = [
        'blog_id'   => 'ブログID',
        'field'     => '項目',
        'old_value' => '変更前',
        'new_value' => '変更後',
        'source'    => '経路',
    ];

    public function getAll(): Collection
    {
        return BlogHistory::orderByDesc('created_at')->get();
    }

    public function findById(int $id): ?BlogHistory
    {
        return BlogHistory::find($id);
    }
}
