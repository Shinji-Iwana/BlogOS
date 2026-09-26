<?php

namespace App\Services\Sync\Resources;

use App\Models\Media;
use App\Services\Sync\SyncContext;

/**
 * メディア（/wp/v2/media）。メディアファイルそのものは保存しない。
 */
class MediaSyncer extends TwoStageSyncer
{
    public function key(): string
    {
        return 'media';
    }

    protected function modelClass(): string
    {
        return Media::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/media';
    }

    protected function statuses(): string
    {
        // メディアには通常ゴミ箱がない（WORDPRESS_API 13-2）
        return 'inherit,private';
    }

    protected function map(array $item, SyncContext $context): array
    {
        $details = $item['media_details'] ?? [];
        $sizes = [];
        // WordPressは取得のたびに順序が変わることがあるため、名前の順にそろえる（順序だけの違いを変更として扱わない）
        $rawSizes = (array) ($details['sizes'] ?? []);
        ksort($rawSizes);
        foreach ($rawSizes as $name => $size) {
            $sizes[$name] = [
                'width'      => $size['width'] ?? null,
                'height'     => $size['height'] ?? null,
                'mime_type'  => $size['mime_type'] ?? null,
                'source_url' => $size['source_url'] ?? null,
            ];
        }

        return array_merge($this->commonColumns($item), [
            'title_raw'            => $this->part($item, 'title', 'raw'),
            'title_rendered'       => $this->part($item, 'title', 'rendered'),
            'caption_raw'          => $this->part($item, 'caption', 'raw'),
            'caption_rendered'     => $this->part($item, 'caption', 'rendered'),
            'description_raw'      => $this->part($item, 'description', 'raw'),
            'description_rendered' => $this->part($item, 'description', 'rendered'),
            'alt_text'             => $item['alt_text'] ?? null,
            'source_url'           => $item['source_url'] ?? null,
            'mime_type'            => $item['mime_type'] ?? null,
            'media_type'           => $item['media_type'] ?? null,
            'width'                => $details['width'] ?? null,
            'height'               => $details['height'] ?? null,
            'filesize'             => $details['filesize'] ?? null,
            'sizes'                => $sizes,
            'wordpress_post_id'    => (int) ($item['post'] ?? 0),
        ]);
    }

    protected function afterDeleted(array $deleted, SyncContext $context): void
    {
        array_push($context->forcedPostIds, ...$this->records->postWordpressIdsLinkedTo(
            'media',
            array_map(fn ($record) => $record->id, $deleted)
        ));
    }

    public function resolveReferences(SyncContext $context): void
    {
        $unresolved = $this->records->resolveReference('media', $context->blog->id, 'wordpress_author_id', 'author_id', 'authors');
        $this->recordUnresolved($context, $unresolved, '投稿者');

        // 添付先は、投稿か固定ページとして解決できた場合だけ設定する。
        // カスタム投稿タイプ等の場合もあるため、見つからなくても問題として記録しない（D-10-02）
        $this->records->resolveReference('media', $context->blog->id, 'wordpress_post_id', 'post_id', 'posts');
        $this->records->resolveReference('media', $context->blog->id, 'wordpress_post_id', 'page_id', 'pages');
    }
}
