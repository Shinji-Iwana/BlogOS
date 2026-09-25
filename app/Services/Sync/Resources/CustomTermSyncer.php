<?php

namespace App\Services\Sync\Resources;

use App\Models\CustomTerm;
use App\Services\Sync\FetchResult;
use App\Services\Sync\SyncContext;

/**
 * カスタムタクソノミーの項目（custom_terms。BLOGOS_DATABASE.md 6-8、WORDPRESS_API 18章）。
 *
 * 同期したタクソノミーの定義（taxonomies）から対象を決め、タクソノミーごとのエンドポイントから全件を取得する。
 * rest_namespace が wp/v2 以外の場合も、定義に従ってエンドポイントを組み立てる。
 */
class CustomTermSyncer extends ListSyncer
{
    public function key(): string
    {
        return 'custom_terms';
    }

    protected function modelClass(): string
    {
        return CustomTerm::class;
    }

    protected function endpoint(): string
    {
        // タクソノミーごとに組み立てるため、使わない
        return '';
    }

    protected function fetch(SyncContext $context, $existing): FetchResult
    {
        $allKeys = [];
        $items = [];

        foreach ($this->records->customDefinitions($context->blog->id, 'taxonomies') as $taxonomy) {
            $result = $this->fetchEndpoint($context, self::endpointFor($taxonomy->rest_namespace, $taxonomy->rest_base));
            array_push($allKeys, ...$result->allKeys);
            $items += $result->items;
        }

        return new FetchResult($allKeys, $items);
    }

    protected function map(array $item, SyncContext $context): array
    {
        return [
            'wordpress_id'        => (int) $item['id'],
            'taxonomy'            => (string) ($item['taxonomy'] ?? ''),
            'name'                => $item['name'] ?? null,
            'slug'                => $item['slug'] ?? null,
            'description'         => $item['description'] ?? null,
            'link'                => $item['link'] ?? null,
            'wordpress_parent_id' => (int) ($item['parent'] ?? 0),
        ];
    }

    public function resolveReferences(SyncContext $context): void
    {
        $unresolved = $this->records->resolveReference('custom_terms', $context->blog->id, 'wordpress_parent_id', 'parent_id', 'custom_terms');
        $this->recordUnresolved($context, $unresolved, '親の項目');
    }

    public static function endpointFor(?string $namespace, string $restBase): string
    {
        return '/wp-json/' . trim($namespace ?: 'wp/v2', '/') . '/' . trim($restBase, '/');
    }
}
