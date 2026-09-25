<?php

namespace App\Services\Blogs;

use App\Clients\WordPress\WordPressApiClient;
use App\Support\HomeUrl;
use Illuminate\Http\Client\ConnectionException;

/**
 * WordPressサイトの確認（ブログ登録・接続確認。BLOGOS_WORDPRESS_API.md 29章）。
 *
 * 各メソッドは、失敗した場合に利用者向けのメッセージを持つ BlogInspectionException を投げる。
 * メッセージに認証情報を含めない。
 */
class WordPressSiteInspector
{
    /**
     * 登録に必要なエンドポイント（BLOGOS_WORDPRESS_API.md 17章の「対象」）
     */
    public const REQUIRED_ROUTES = [
        '/wp/v2/posts',
        '/wp/v2/pages',
        '/wp/v2/categories',
        '/wp/v2/tags',
        '/wp/v2/users',
        '/wp/v2/media',
        '/wp/v2/settings',
        '/wp/v2/types',
        '/wp/v2/statuses',
        '/wp/v2/taxonomies',
    ];

    /**
     * 入力されたURLからAPI Rootを見つけ、ホームURLを確定する（WORDPRESS_API 29章 2〜4）。
     *
     * 1. <入力URL>/wp-json/ を取得する
     * 2. 取得できない場合は、入力URLのHTMLにある <link rel="https://api.w.org/"> からAPI Rootを探す
     *
     * @return array{home: string, root: array}
     */
    public function discover(string $inputUrl): array
    {
        $base = rtrim(preg_replace('#/wp-json/?$#', '', trim($inputUrl)), '/');

        $root = $this->fetchJson("{$base}/wp-json/");

        if ($root === null) {
            $apiRootUrl = $this->findApiRootFromHtml($base);
            $root = $apiRootUrl !== null ? $this->fetchJson($apiRootUrl) : null;
        }

        if ($root === null) {
            throw new BlogInspectionException(
                "「{$base}」でWordPress REST APIが見つかりませんでした。URLが正しいか、サイトが公開されているかを確認してください。"
            );
        }

        if (! in_array('wp/v2', $root['namespaces'] ?? [], true)) {
            throw new BlogInspectionException('WordPress REST API（wp/v2）が利用できません。');
        }

        if (blank($root['home'] ?? null)) {
            throw new BlogInspectionException('API Rootからサイトアドレス（home）を取得できませんでした。');
        }

        return [
            'home' => HomeUrl::normalize($root['home']),
            'root' => $root,
        ];
    }

    /**
     * 必要なエンドポイントがあるかを確認する（WORDPRESS_API 29章 6）。
     *
     * @return array<int, string> 見つからなかったエンドポイント
     */
    public function findMissingRoutes(array $root): array
    {
        $routes = array_keys($root['routes'] ?? []);

        return array_values(array_diff(self::REQUIRED_ROUTES, $routes));
    }

    /**
     * 認証情報で /wp/v2/users/me を取得できるかを確認する（WORDPRESS_API 29章 5）。
     *
     * @return array 認証したWordPressユーザー（name・roles 等）
     */
    public function verifyCredentials(WordPressApiClient $client): array
    {
        try {
            $response = $client->get('/wp-json/wp/v2/users/me', ['context' => 'edit']);
        } catch (ConnectionException $e) {
            throw new BlogInspectionException('WordPressに接続できませんでした。時間をおいて再度お試しください。');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new BlogInspectionException(
                'WordPressの認証に失敗しました。ユーザー名とApplication Passwordを確認してください。'
            );
        }

        if ($response->failed() || ! is_array($response->json())) {
            throw new BlogInspectionException("認証の確認に失敗しました（HTTP {$response->status()}）。");
        }

        return $response->json();
    }

    /**
     * BlogOS連携用のWordPress側の拡張（_blogos_draft_id）が有効かを判定する（WORDPRESS_API 26章）。
     *
     * @return bool|null 有効なら true、無効なら false、投稿がなく判定できない場合は null
     */
    public function hasConnectorExtension(WordPressApiClient $client): ?bool
    {
        try {
            $response = $client->get('/wp-json/wp/v2/posts', [
                'context'  => 'edit',
                'per_page' => 1,
                'status'   => 'publish,future,draft,pending,private',
                '_fields'  => 'id,meta',
            ]);
        } catch (ConnectionException $e) {
            return null;
        }

        $posts = $response->successful() ? $response->json() : null;

        if (! is_array($posts) || $posts === []) {
            return null;
        }

        $meta = $posts[0]['meta'] ?? [];

        return is_array($meta) && array_key_exists('_blogos_draft_id', $meta);
    }

    /**
     * WordPressのサイト設定を取得する（WORDPRESS_API 10-1）。
     * timezone_string・gmt_offset は API Root の値を使う。
     *
     * @return array<string, mixed>
     */
    public function fetchSettings(WordPressApiClient $client, array $root): array
    {
        try {
            $response = $client->get('/wp-json/wp/v2/settings');
        } catch (ConnectionException $e) {
            throw new BlogInspectionException('サイト設定を取得できませんでした（接続エラー）。');
        }

        if ($response->failed() || ! is_array($response->json())) {
            throw new BlogInspectionException(
                "サイト設定を取得できませんでした（HTTP {$response->status()}）。管理者権限のApplication Passwordか確認してください。"
            );
        }

        $settings = $response->json();

        $settings['home'] = $root['home'] ?? ($settings['home'] ?? null);
        $settings['timezone_string'] = $root['timezone_string'] ?? null;
        $settings['gmt_offset'] = $root['gmt_offset'] ?? null;

        return $settings;
    }

    protected function fetchJson(string $url): ?array
    {
        try {
            $response = WordPressApiClient::fetchUrl($url);
        } catch (ConnectionException $e) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }

    protected function findApiRootFromHtml(string $url): ?string
    {
        try {
            $response = WordPressApiClient::fetchUrl($url);
        } catch (ConnectionException $e) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        if (preg_match('#<link[^>]+rel=["\']https://api\.w\.org/["\'][^>]*href=["\']([^"\']+)["\']#i', $response->body(), $m)) {
            return html_entity_decode($m[1]);
        }

        return null;
    }
}
