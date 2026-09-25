<?php

namespace App\Services\Sync\Resources;

use App\Models\Category;
use App\Services\Sync\SyncContext;

/**
 * カテゴリ（/wp/v2/categories）。
 * 投稿数（count）は保存しない（D-13-03）。
 */
class CategorySyncer extends ListSyncer
{
    public function key(): string
    {
        return 'categories';
    }

    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/categories';
    }

    protected function map(array $item, SyncContext $context): array
    {
        return [
            'wordpress_id'        => (int) $item['id'],
            'name'                => $item['name'] ?? null,
            'slug'                => $item['slug'] ?? null,
            'description'         => $item['description'] ?? null,
            'link'                => $item['link'] ?? null,
            'wordpress_parent_id' => (int) ($item['parent'] ?? 0),
        ];
    }

    protected function afterDeleted(array $deleted, SyncContext $context): void
    {
        array_push($context->forcedPostIds, ...$this->records->postWordpressIdsLinkedTo(
            'categories',
            array_map(fn ($record) => $record->id, $deleted)
        ));
    }

    public function resolveReferences(SyncContext $context): void
    {
        $unresolved = $this->records->resolveReference('categories', $context->blog->id, 'wordpress_parent_id', 'parent_id', 'categories');
        $this->recordUnresolved($context, $unresolved, '親カテゴリ');
    }
}
