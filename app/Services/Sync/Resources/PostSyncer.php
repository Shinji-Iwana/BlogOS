<?php

namespace App\Services\Sync\Resources;

use App\Models\Post;
use App\Models\WordPressRecord;
use App\Services\Sync\SyncContext;

/**
 * 投稿（/wp/v2/posts）。本文は raw と rendered の両方を保存する（D-05-07）。
 * カテゴリ・タグの付け替えは、post_histories に categories / tags として記録する（D-13-05）。
 */
class PostSyncer extends ArticleSyncer
{
    public function key(): string
    {
        return 'posts';
    }

    protected function modelClass(): string
    {
        return Post::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/posts';
    }

    protected function statuses(): string
    {
        // ゴミ箱を含める。ゴミ箱への移動と完全削除を区別するため（D-04-06、D-09-01）
        return 'publish,future,draft,pending,private,trash';
    }

    protected function forcedIds(SyncContext $context): array
    {
        // 削除されたカテゴリ・タグ・メディアに関連していた投稿は、modified_gmt が変わらなくても取得し直す（D-09-04）
        return array_values(array_unique($context->forcedPostIds));
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
            'format'                      => $item['format'] ?? null,
            'sticky'                      => (bool) ($item['sticky'] ?? false),
        ], $this->metaDescriptionColumns($item));
    }

    protected function afterSave(WordPressRecord $record, array $item, SyncContext $context): array
    {
        $changes = parent::afterSave($record, $item, $context);

        $categories = $this->records->syncPivot($record, 'post_id', 'post_categories', 'categories', 'category_id', $item['categories'] ?? []);
        if ($categories !== null) {
            $changes['categories'] = $categories;
        }

        $tags = $this->records->syncPivot($record, 'post_id', 'post_tags', 'tags', 'tag_id', $item['tags'] ?? []);
        if ($tags !== null) {
            $changes['tags'] = $tags;
        }

        return $changes;
    }

    public function resolveReferences(SyncContext $context): void
    {
        $blogId = $context->blog->id;

        $this->recordUnresolved($context, $this->records->resolveReference('posts', $blogId, 'wordpress_author_id', 'author_id', 'authors'), '投稿者');
        $this->recordUnresolved($context, $this->records->resolveReference('posts', $blogId, 'wordpress_featured_media_id', 'featured_media_id', 'media'), 'アイキャッチ画像');
    }
}
