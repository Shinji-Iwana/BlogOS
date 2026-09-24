<?php

namespace App\Repositories;

use App\DTO\WordPress\StatusApiDto;
use App\Models\Status;
use App\Models\StatusHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StatusRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * name：ステータス名：公開済み
     * private：非公開扱いか：false
     * protected：保護されているか：false
     * public：フロントに公開されるか：true
     * queryable：公開検索可能か：true
     * show_in_list：管理画面一覧に表示するか：true
     * slug：ステータス識別子：publish
     * date_floating：日付を固定しないか：false
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Status::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Status
    {
        return Status::find($id);
    }
}
