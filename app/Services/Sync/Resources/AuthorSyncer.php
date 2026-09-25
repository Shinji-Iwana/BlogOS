<?php

namespace App\Services\Sync\Resources;

use App\Models\Author;
use App\Services\Sync\SyncContext;

/**
 * WordPressのユーザー（/wp/v2/users → authors）。
 * ログイン名・メールアドレス・権限の詳細は保存しない（D-05-04）。
 */
class AuthorSyncer extends ListSyncer
{
    public function key(): string
    {
        return 'authors';
    }

    protected function modelClass(): string
    {
        return Author::class;
    }

    protected function endpoint(): string
    {
        return '/wp-json/wp/v2/users';
    }

    protected function map(array $item, SyncContext $context): array
    {
        return [
            'wordpress_id' => (int) $item['id'],
            'name'         => $item['name'] ?? null,
            'slug'         => $item['slug'] ?? null,
            'url'          => $item['url'] ?? null,
            'description'  => $item['description'] ?? null,
            'link'         => $item['link'] ?? null,
            'avatar_urls'  => $item['avatar_urls'] ?? [],
            'roles'        => $item['roles'] ?? [],
        ];
    }
}
