<?php

namespace App\Services\Sync\Resources;

use App\Models\Page;
use App\Services\Sync\SyncContext;

/**
 * 固定ページ（/wp/v2/pages）。本文は raw と rendered の両方を保存する（D-05-07）。
 */
class PageSyncer extends ArticleSyncer
{
    public function key(): string
    {
        return 'pages';
    }

    protected function modelClass(): string
    {
        return Page::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/pages';
    }

    protected function statuses(): string
    {
        // ゴミ箱を含める。ゴミ箱への移動と完全削除を区別するため（D-04-06、D-09-01）
        return 'publish,future,draft,pending,private,trash';
    }

    protected function map(array $item, SyncContext $context): array
    {
        return array_merge($this->commonColumns($item), [
            'title_raw'                   => $this->part($item, 'title', 'raw'),
            'title_rendered'              => $this->part($item, 'title', 'rendered'),
            'content_raw'                 => $this->part($item, 'content', 'raw'),
            'content_rendered'            => $this->part($item, 'content', 'rendered'),
            'excerpt_raw'                 => $this->part($item, 'excerpt', 'raw'),
            'excerpt_rendered'            => $this->part($item, 'excerpt', 'rendered'),
            'type'                        => $item['type'] ?? null,
            'template'                    => $item['template'] ?? null,
            'comment_status'              => $item['comment_status'] ?? null,
            'ping_status'                 => $item['ping_status'] ?? null,
            'normalized_path'             => $this->normalizedPath($item['link'] ?? null),
            'wordpress_featured_media_id' => (int) ($item['featured_media'] ?? 0),
            'wordpress_parent_id'         => (int) ($item['parent'] ?? 0),
            'menu_order'                  => (int) ($item['menu_order'] ?? 0),
        ], $this->metaDescriptionColumns($item));
    }

    public function resolveReferences(SyncContext $context): void
    {
        $blogId = $context->blog->id;

        $this->recordUnresolved($context, $this->records->resolveReference('pages', $blogId, 'wordpress_author_id', 'author_id', 'authors'), '投稿者');
        $this->recordUnresolved($context, $this->records->resolveReference('pages', $blogId, 'wordpress_featured_media_id', 'featured_media_id', 'media'), 'アイキャッチ画像');
        $this->recordUnresolved($context, $this->records->resolveReference('pages', $blogId, 'wordpress_parent_id', 'parent_id', 'pages'), '親ページ');
    }
}
