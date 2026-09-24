<?php

namespace App\Repositories;

use App\Models\StatusHistory;
use Illuminate\Database\Eloquent\Collection;

class StatusHistoryRepository
{

    public function getAll(int $blogId): Collection
    {
        return StatusHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?StatusHistory
    {
        return StatusHistory::find($id);
    }
}
