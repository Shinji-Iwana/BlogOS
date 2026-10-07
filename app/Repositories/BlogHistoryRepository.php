<?php

namespace App\Repositories;

use App\Models\BlogHistory;
use App\Models\BlogSettingHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BlogHistoryRepository
{
    public const FIELD_LABELS = [
        'blog_id'       => 'ブログID',
        'change_set_id' => '変更のまとまり',
        'field'         => '項目',
        'old_value'     => '変更前',
        'new_value'     => '変更後',
        'source'        => '変更元',
        'user_id'       => '利用者',
        'changed_at'    => '変更日時',
    ];

    /**
     * blogs の変更履歴（新しい順。ページに分ける）
     */
    public function paginate(int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        return BlogHistory::with(['blog', 'user'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], $pageName);
    }

    public function findById(int $id): ?BlogHistory
    {
        return BlogHistory::with(['blog', 'user'])->find($id);
    }

    /**
     * blog_settings（WordPressのサイト設定）の変更履歴（新しい順。ページに分ける）
     */
    public function paginateSettingHistories(int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        return BlogSettingHistory::with(['blog', 'setting'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], $pageName);
    }
}
