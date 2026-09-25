<?php

namespace App\Services\Sync\Resources;

use App\Models\Tag;
use App\Services\Sync\SyncContext;

/**
 * タグ（/wp/v2/tags）。
 * 投稿数（count）は保存しない（D-13-03）。
 */
class TagSyncer extends ListSyncer
{
    public function key(): string
    {
        return 'tags';
    }

    protected function modelClass(): string
    {
        return Tag::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/tags';
    }

    protected function map(array $item, SyncContext $context): array
    {
        return [
            'wordpress_id' => (int) $item['id'],
            'name'         => $item['name'] ?? null,
            'slug'         => $item['slug'] ?? null,
            'description'  => $item['description'] ?? null,
            'link'         => $item['link'] ?? null,
        ];
    }

    protected function afterDeleted(array $deleted, SyncContext $context): void
    {
        array_push($context->forcedPostIds, ...$this->records->postWordpressIdsLinkedTo(
            'tags',
            array_map(fn ($record) => $record->id, $deleted)
        ));
    }
}
