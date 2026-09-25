<?php

namespace App\Services\Sync\Resources;

use App\Services\Sync\FetchResult;
use App\Services\Sync\SyncContext;

/**
 * 更新日時を持たないリソース（投稿者・カテゴリ・タグ）の同期。
 * 毎回全件を取得して比較する（BLOGOS_WORDPRESS_API.md 15-2）。
 */
abstract class ListSyncer extends AbstractResourceSyncer
{
    abstract protected function endpoint(): string;

    protected function fetch(SyncContext $context, $existing): FetchResult
    {
        $items = [];
        foreach ($context->client->getAllPages($this->endpoint(), [
            'context' => 'edit',
            'orderby' => 'id',
            'order'   => 'asc',
        ]) as $item) {
            if (isset($item['id'])) {
                $items[(string) $item['id']] = $item;
            }
        }

        return new FetchResult(array_keys($items), $items);
    }
}
