<?php

namespace App\Services\Sync\Resources;

use App\Models\WordPressRecord;
use App\Services\Sync\FetchResult;
use App\Services\Sync\SyncContext;
use App\Support\HomeUrl;
use Illuminate\Support\Carbon;

/**
 * 更新日時を持つリソース（メディア・固定ページ・投稿）の同期。2段階で取得する（D-04-05、WORDPRESS_API 15-1）。
 *
 * 1. IDと更新日時（modified_gmt）だけを全ページ取得する
 * 2. DBと比べ、新規・変更（と、取得し直しを指定されたもの）だけを include で詳細まで取得する
 */
abstract class TwoStageSyncer extends AbstractResourceSyncer
{
    abstract protected function endpoint(): string;

    /**
     * 取得するステータス（WORDPRESS_API 13-2）
     */
    abstract protected function statuses(): string;

    /**
     * 詳細まで取得し直すWordPress ID（投稿では、削除されたカテゴリ等に関連していたもの）
     *
     * @return array<int, int>
     */
    protected function forcedIds(SyncContext $context): array
    {
        return [];
    }

    protected function fetch(SyncContext $context, $existing): FetchResult
    {
        $list = $context->client->getAllPages($this->endpoint(), [
            'context' => 'edit',
            'status'  => $this->statuses(),
            '_fields' => 'id,modified_gmt',
            'orderby' => 'id',
            'order'   => 'asc',
        ]);

        $allKeys = [];
        $targets = [];
        $forced = array_flip($this->forcedIds($context));

        foreach ($list as $row) {
            if (! isset($row['id'])) {
                continue;
            }

            $key = (string) $row['id'];
            $allKeys[] = $key;

            /** @var WordPressRecord|null $record */
            $record = $existing->get($key);

            if (
                $record === null
                || $record->wordpress_deleted_at !== null
                || isset($forced[(int) $key])
                || ! $this->sameModified($record->wordpress_modified_gmt, $row['modified_gmt'] ?? null)
            ) {
                $targets[] = (int) $key;
            }
        }

        $items = [];
        foreach (array_chunk($targets, 100) as $chunk) {
            foreach ($context->client->getAllPages($this->endpoint(), [
                'context' => 'edit',
                'status'  => $this->statuses(),
                'include' => implode(',', $chunk),
                'orderby' => 'id',
                'order'   => 'asc',
            ]) as $item) {
                if (isset($item['id'])) {
                    $items[(string) $item['id']] = $item;
                }
            }
        }

        return new FetchResult($allKeys, $items);
    }

    protected function sameModified(?Carbon $stored, ?string $fromApi): bool
    {
        if ($stored === null || $fromApi === null) {
            return false;
        }

        return $stored->format('Y-m-d H:i:s') === $this->datetime($fromApi);
    }

    /**
     * 投稿・固定ページ・メディアに共通の列
     */
    protected function commonColumns(array $item): array
    {
        return [
            'wordpress_id'           => (int) $item['id'],
            'wordpress_date'         => $this->datetime($item['date'] ?? null),
            'wordpress_date_gmt'     => $this->datetime($item['date_gmt'] ?? null),
            'wordpress_modified'     => $this->datetime($item['modified'] ?? null),
            'wordpress_modified_gmt' => $this->datetime($item['modified_gmt'] ?? null),
            'slug'                   => $item['slug'] ?? null,
            'status'                 => $item['status'] ?? null,
            'link'                   => $item['link'] ?? null,
            'wordpress_author_id'    => (int) ($item['author'] ?? 0),
        ];
    }

    /**
     * link からドメイン・末尾のスラッシュ・クエリを除いたパス（D-08-05）
     */
    protected function normalizedPath(?string $link): ?string
    {
        if (blank($link)) {
            return null;
        }

        $path = parse_url($link, PHP_URL_PATH) ?? '/';
        $path = '/' . trim(rawurldecode($path), '/');

        return mb_substr($path, 0, 500);
    }
}
