<?php

namespace App\Repositories;

use App\Models\TagHistory;
use Illuminate\Database\Eloquent\Collection;

class TagHistoryRepository
{

    public function getAll(int $blogId): Collection
    {
        return TagHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?TagHistory
    {
        return TagHistory::find($id);
    }
}
