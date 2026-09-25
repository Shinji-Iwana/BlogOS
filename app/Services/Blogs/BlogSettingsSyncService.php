<?php

namespace App\Services\Blogs;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Repositories\BlogSettingRepository;
use App\Support\HomeUrl;

/**
 * WordPressのサイト設定の同期（blog_settings）。同期（App\Services\Sync\SyncService）の最初に行う。
 */
class BlogSettingsSyncService
{
    public function __construct(
        protected WordPressSiteInspector $inspector,
        protected BlogSettingRepository $blogSettingRepository
    ) {
    }

    /**
     * @return array{changed: array<int, string>, home_from_wordpress: string|null, home_changed: bool}
     */
    public function sync(Blog $blog, ChangeSource $source = ChangeSource::WpSync, ?int $syncRunId = null): array
    {
        $client = WordPressApiClient::forBlog($blog);

        $root = $this->inspector->discover($blog->home)['root'];
        $settings = $this->inspector->fetchSettings($client, $root);

        $changed = $this->blogSettingRepository->sync($blog, $settings, $source, null, $syncRunId);

        // サイトアドレスが変わっていても、接続先が意図せず変わらないよう blogs.home は自動で更新しない。
        // 呼び出し元が sync_issues に記録し、人が確認する（D-13-01）
        $homeFromWordPress = $settings['home'] ?? null;

        return [
            'changed'             => $changed,
            'home_from_wordpress' => $homeFromWordPress,
            'home_changed'        => $homeFromWordPress !== null
                && HomeUrl::comparisonKey((string) $homeFromWordPress) !== HomeUrl::comparisonKey($blog->home),
        ];
    }
}
