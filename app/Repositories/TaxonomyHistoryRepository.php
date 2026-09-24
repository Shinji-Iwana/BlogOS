<?php

namespace App\Repositories;

use App\Models\TaxonomyHistory;
use Illuminate\Database\Eloquent\Collection;

class TaxonomyHistoryRepository
{

    public function getAll(int $blogId): Collection
    {
        return TaxonomyHistory::where('blog_id', $blogId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findById(int $id): ?TaxonomyHistory
    {
        return TaxonomyHistory::find($id);
    }
}
