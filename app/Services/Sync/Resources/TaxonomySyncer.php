<?php

namespace App\Services\Sync\Resources;

use App\Models\Taxonomy;
use App\Services\Sync\SyncContext;

/**
 * タクソノミーの定義（/wp/v2/taxonomies）
 */
class TaxonomySyncer extends DefinitionSyncer
{
    public function key(): string
    {
        return 'taxonomies';
    }

    protected function modelClass(): string
    {
        return Taxonomy::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/taxonomies';
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
            'types'          => $item['types'] ?? [],
        ];
    }
}
