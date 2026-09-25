<?php

namespace App\Services\Sync\Resources;

use App\Services\Sync\FetchResult;
use App\Services\Sync\SyncContext;

/**
 * WordPressの定義情報（statuses・types・taxonomies）の同期。
 *
 * これらのAPIは、slug をキーにしたオブジェクトを1回で返す（ページ送りなし）。
 * 数値IDを持たないため、slug で識別する（D-02-04）。
 */
abstract class DefinitionSyncer extends AbstractResourceSyncer
{
    abstract protected function endpoint(): string;

    protected function keyColumn(): string
    {
        return 'slug';
    }

    protected function fetch(SyncContext $context, $existing): FetchResult
    {
        $data = $context->client->getOrFail($this->endpoint(), ['context' => 'edit'])->json();

        $items = [];
        foreach ($data as $slug => $item) {
            if (is_array($item)) {
                $items[(string) $slug] = $item + ['slug' => (string) $slug];
            }
        }

        return new FetchResult(array_keys($items), $items);
    }
}
