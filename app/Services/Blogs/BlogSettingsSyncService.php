<?php

namespace App\Services\Blogs;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Repositories\BlogSettingRepository;
use App\Support\HomeUrl;
use Illuminate\Support\Facades\Log;

/**
 * WordPressのサイト設定の同期（blog_settings）。
 *
 * 同期の仕組み全体（sync_runs 等）は段階3で作る。それまでは、ブログ単位でサイト設定だけを同期する。
 */
class BlogSettingsSyncService
{
    public function __construct(
        protected WordPressSiteInspector $inspector,
        protected BlogSettingRepository $blogSettingRepository
    ) {
    }

    /**
     * @return array<int, string> 作成・変更したキー
     */
    public function sync(Blog $blog, ChangeSource $source = ChangeSource::WpSync): array
    {
        $client = WordPressApiClient::forBlog($blog);

        $root = $this->inspector->discover($blog->home)['root'];
        $settings = $this->inspector->fetchSettings($client, $root);

        // サイトアドレスが変わった場合は、接続先が意図せず変わらないよう blogs.home は自動で更新しない。
        // 同期の問題（sync_issues）への記録は段階3で行う。それまではログに残す（D-13-01）。
        if (HomeUrl::comparisonKey((string) $settings['home']) !== HomeUrl::comparisonKey($blog->home)) {
            Log::warning('WordPressのサイトアドレス（home）が、登録されているホームURLと異なります。', [
                'blog_id'       => $blog->id,
                'registered'    => $blog->home,
                'from_wordpress' => $settings['home'],
            ]);
        }

        return $this->blogSettingRepository->sync($blog, $settings, $source);
    }
}
