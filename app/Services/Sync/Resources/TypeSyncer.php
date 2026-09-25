<?php

namespace App\Services\Sync\Resources;

use App\Models\Type;
use App\Services\Sync\SyncContext;

/**
 * 投稿タイプの定義（/wp/v2/types）
 */
class TypeSyncer extends DefinitionSyncer
{
    public function key(): string
    {
        return 'types';
    }

    protected function modelClass(): string
    {
        return Type::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/types';
    }

    protected function map(array $item, SyncContext $context): array
    {
        return [
            'slug'           => $item['slug'],
            'name'           => $item['name'] ?? null,
            'description'    => $item['description'] ?? null,
            'hierarchical'   => $item['hierarchical'] ?? null,
            'rest_base'      => $item['rest_base'] ?? null,
            'rest_namespace' => $item['rest_namespace'] ?? null,
            'taxonomies'     => $item['taxonomies'] ?? [],
        ];
    }
}
