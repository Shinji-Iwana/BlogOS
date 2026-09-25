<?php

namespace App\Services\Sync\Resources;

use App\Models\Status;
use App\Services\Sync\SyncContext;

/**
 * 投稿ステータスの定義（/wp/v2/statuses）
 */
class StatusSyncer extends DefinitionSyncer
{
    public function key(): string
    {
        return 'statuses';
    }

    protected function modelClass(): string
    {
        return Status::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/statuses';
    }

    protected function map(array $item, SyncContext $context): array
    {
        return [
            'slug'         => $item['slug'],
            'name'         => $item['name'] ?? null,
            'public'       => $item['public'] ?? null,
            'queryable'    => $item['queryable'] ?? null,
            'show_in_list' => $item['show_in_list'] ?? null,
        ];
    }
}
