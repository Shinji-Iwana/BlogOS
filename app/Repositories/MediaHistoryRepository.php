<?php

namespace App\Repositories;

use App\Models\MediaHistory;
use Illuminate\Database\Eloquent\Collection;

class MediaHistoryRepository
{

    public const FIELD_LABELS = [
        'blog_id'   => 'ブログID',
        'media_id'  => 'メディアID',
        'field'     => '項目',
        'old_value' => '変更前',
        'new_value' => '変更後',
        'source'    => '経路',
    ];

    public function getAll(int $blogId): Collection
    {
        return MediaHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?MediaHistory
    {
        return MediaHistory::find($id);
    }
}
