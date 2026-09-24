<?php

namespace App\Repositories;

use App\Models\TypeHistory;
use Illuminate\Database\Eloquent\Collection;

class TypeHistoryRepository
{

    public function getAll(int $blogId): Collection
    {
        return TypeHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?TypeHistory
    {
        return TypeHistory::find($id);
    }
}
