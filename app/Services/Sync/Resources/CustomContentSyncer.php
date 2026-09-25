<?php

namespace App\Services\Sync\Resources;

use App\Models\CustomContent;
use App\Models\WordPressRecord;
use App\Services\Sync\FetchResult;
use App\Services\Sync\SyncContext;

/**
 * カスタム投稿タイプの内容（custom_contents。BLOGOS_DATABASE.md 6-8、WORDPRESS_API 18章）。
 *
 * 同期した投稿タイプの定義（types）から対象を決め、投稿タイプごとに2段階で取得する。
 * 同期と閲覧だけの対象とし、競合の判定・本文からの抽出は行わない（編集案・反映の対象外のため）。
 */
class CustomContentSyncer extends TwoStageSyncer
{
    public function key(): string
    {
        return 'custom_contents';
    }

    protected function modelClass(): string
    {
        return CustomContent::class;
    }

    protected function endpoint(): string
    {
        // 投稿タイプごとに組み立てるため、使わない
        return '';
    }

    protected function statuses(): string
    {
        return 'publish,future,draft,pending,private,trash';
    }

    protected function fetch(SyncContext $context, $existing): FetchResult
    {
        $allKeys = [];
        $items = [];

        foreach ($this->records->customDefinitions($context->blog->id, 'types') as $type) {
            $result = $this->fetchEndpoint($context, $existing, CustomTermSyncer::endpointFor($type->rest_namespace, $type->rest_base));
            array_push($allKeys, ...$result->allKeys);
            $items += $result->items;
        }

        return new FetchResult($allKeys, $items);
    }

    protected function map(array $item, SyncContext $context): array
    {
        return array_merge($this->commonColumns($item), [
            'type'                        => (string) ($item['type'] ?? ''),
            'title_raw'                   => $this->part($item, 'title', 'raw'),
            'title_rendered'              => $this->part($item, 'title', 'rendered'),
            'content_raw'                 => $this->part($item, 'content', 'raw'),
            'content_rendered'            => $this->part($item, 'content', 'rendered'),
            'excerpt_raw'                 => $this->part($item, 'excerpt', 'raw'),
            'excerpt_rendered'            => $this->part($item, 'excerpt', 'rendered'),
            'template'                    => $item['template'] ?? null,
            'normalized_path'             => $this->normalizedPath($item['link'] ?? null),
            'wordpress_featured_media_id' => (int) ($item['featured_media'] ?? 0),
            'wordpress_parent_id'         => (int) ($item['parent'] ?? 0),
            'menu_order'                  => (int) ($item['menu_order'] ?? 0),
        ]);
    }

    /**
     * カスタムタクソノミーの関連を更新する。項目は、タクソノミーの rest_base をキーにしてWordPress IDの一覧で返る
     */
    protected function afterSave(WordPressRecord $record, array $item, SyncContext $context): array
    {
        $ids = [];
        foreach ($this->records->customDefinitions($context->blog->id, 'taxonomies') as $taxonomy) {
            foreach ((array) ($item[$taxonomy->rest_base] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }

        $terms = $this->records->syncPivot($record, 'custom_content_id', 'custom_content_terms', 'custom_terms', 'custom_term_id', $ids);

        return $terms === null ? [] : ['terms' => $terms];
    }

    public function resolveReferences(SyncContext $context): void
    {
        $blogId = $context->blog->id;

        $this->recordUnresolved($context, $this->records->resolveReference('custom_contents', $blogId, 'wordpress_author_id', 'author_id', 'authors'), '投稿者');
        $this->recordUnresolved($context, $this->records->resolveReference('custom_contents', $blogId, 'wordpress_featured_media_id', 'featured_media_id', 'media'), 'アイキャッチ画像');
        $this->recordUnresolved($context, $this->records->resolveReference('custom_contents', $blogId, 'wordpress_parent_id', 'parent_id', 'custom_contents'), '親');
    }
}
