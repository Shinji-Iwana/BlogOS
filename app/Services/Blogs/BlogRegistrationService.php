<?php

namespace App\Services\Blogs;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Repositories\BlogCredentialRepository;
use App\Repositories\BlogRepository;
use App\Repositories\BlogSettingRepository;
use Illuminate\Support\Facades\DB;

/**
 * ブログの登録（BLOGOS_WORDPRESS_API.md 29章）。
 *
 * ブラウザから送られた値は、URL・認証情報・品質基準の識別子だけを使う。
 * サイト名やホームURLなどは、サーバー側でWordPressから取得し直した値を保存する。
 */
class BlogRegistrationService
{
    public function __construct(
        protected WordPressSiteInspector $inspector,
        protected BlogRepository $blogRepository,
        protected BlogSettingRepository $blogSettingRepository,
        protected BlogCredentialRepository $blogCredentialRepository
    ) {
    }

    /**
     * @return array{blog: Blog, wordpress_user: array, connector_extension: bool|null}
     */
    public function register(
        string $inputUrl,
        string $username,
        string $applicationPassword,
        ?string $qualityProfile,
        ?int $userId
    ): array {
        // 1〜4：API Discovery とホームURLの確定、重複の確認
        $discovered = $this->inspector->discover($inputUrl);
        $home = $discovered['home'];
        $root = $discovered['root'];

        if ($this->blogRepository->findByHome($home) !== null) {
            throw new BlogInspectionException("このブログ（{$home}）は既に登録されています。");
        }

        // 5：認証の確認
        $client = new WordPressApiClient($home, $username, $applicationPassword);
        $wordpressUser = $this->inspector->verifyCredentials($client);

        // 6：必要なエンドポイントの確認
        $missing = $this->inspector->findMissingRoutes($root);
        if ($missing !== []) {
            throw new BlogInspectionException('必要なAPIが見つかりません：' . implode(', ', $missing));
        }

        // 7：WordPress側の拡張の判定（無効でも登録はできる。D-01-12）
        $connectorExtension = $this->inspector->hasConnectorExtension($client);

        $settings = $this->inspector->fetchSettings($client, $root);

        $blog = DB::transaction(function () use ($home, $settings, $root, $qualityProfile, $userId, $username, $applicationPassword) {
            $blog = $this->blogRepository->create(
                $home,
                (string) ($settings['title'] ?? $root['name'] ?? $home),
                $qualityProfile,
                $userId
            );

            $this->blogCredentialRepository->save($blog, $username, $applicationPassword);

            // 初回取得として、サイト設定を保存する
            $this->blogSettingRepository->sync($blog, $settings, ChangeSource::WpInitialSync, $userId);

            return $blog;
        });

        return [
            'blog'                => $blog,
            'wordpress_user'      => $wordpressUser,
            'connector_extension' => $connectorExtension,
        ];
    }
}
