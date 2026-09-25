<?php

namespace App\Services\WordPressApi;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\Blog;
use Illuminate\Http\Client\ConnectionException;

/**
 * API確認画面のための取得（BLOGOS_ARCHITECTURE.md 6-3、21-2）。
 *
 * WordPress APIを、その場で呼び出して結果をそのまま返す。DBには保存しない（D-10-03）。
 */
class ApiInspectionService
{
    /**
     * API確認画面で扱うリソース（BLOGOS_WORDPRESS_API.md 17章の「対象」）
     *
     * endpoint：ホームURLからのパス
     * list：一覧の形式（list＝配列、map＝slugをキーにしたオブジェクト、single＝1件）
     * title：一覧で表示する項目
     */
    public const RESOURCES = [
        'root'       => ['label' => 'API Root',       'endpoint' => '/wp-json/',                 'list' => 'single'],
        'settings'   => ['label' => 'Settings',       'endpoint' => '/wp-json/wp/v2/settings',   'list' => 'single'],
        'posts'      => ['label' => '投稿',           'endpoint' => '/wp-json/wp/v2/posts',      'list' => 'list', 'title' => 'title'],
        'pages'      => ['label' => '固定ページ',     'endpoint' => '/wp-json/wp/v2/pages',      'list' => 'list', 'title' => 'title'],
        'categories' => ['label' => 'カテゴリ',       'endpoint' => '/wp-json/wp/v2/categories', 'list' => 'list', 'title' => 'name'],
        'tags'       => ['label' => 'タグ',           'endpoint' => '/wp-json/wp/v2/tags',       'list' => 'list', 'title' => 'name'],
        'users'      => ['label' => 'ユーザー',       'endpoint' => '/wp-json/wp/v2/users',      'list' => 'list', 'title' => 'name'],
        'media'      => ['label' => 'メディア',       'endpoint' => '/wp-json/wp/v2/media',      'list' => 'list', 'title' => 'title'],
        'statuses'   => ['label' => 'ステータス',     'endpoint' => '/wp-json/wp/v2/statuses',   'list' => 'map',  'title' => 'name'],
        'types'      => ['label' => '投稿タイプ',     'endpoint' => '/wp-json/wp/v2/types',      'list' => 'map',  'title' => 'name'],
        'taxonomies' => ['label' => 'タクソノミー',   'endpoint' => '/wp-json/wp/v2/taxonomies', 'list' => 'map',  'title' => 'name'],
    ];

    /**
     * 画面から指定できる問い合わせ条件（WordPress APIのパラメータ名のまま）
     */
    public const QUERY_KEYS = ['context', 'page', 'per_page', 'status', 'search', 'orderby', 'order'];

    /**
     * @return array{
     *   method: string, url: string, query: array, status: int|null,
     *   headers: array, body: mixed, raw: string|null, error: string|null
     * }
     */
    public function fetch(Blog $blog, string $resource, ?string $id, array $query): array
    {
        $definition = self::RESOURCES[$resource];
        $endpoint = $definition['endpoint'] . ($id !== null ? '/' . rawurlencode($id) : '');
        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

        $result = [
            'method'  => 'GET',
            'url'     => rtrim($blog->home, '/') . $endpoint,
            'query'   => $query,
            'status'  => null,
            'headers' => [],
            'body'    => null,
            'raw'     => null,
            'error'   => null,
        ];

        try {
            $response = WordPressApiClient::forBlog($blog)->get($endpoint, $query);
        } catch (ConnectionException $e) {
            $result['error'] = "WordPressに接続できませんでした：{$e->getMessage()}";

            return $result;
        }

        $result['status'] = $response->status();
        $result['headers'] = $response->headers();
        $result['raw'] = $response->body();
        $result['body'] = $response->json();

        return $result;
    }

    /**
     * 一覧で表示する行（ID・slug・名前）を取り出す。形式が違う場合は空の配列を返す。
     */
    public function rows(string $resource, mixed $body): array
    {
        $definition = self::RESOURCES[$resource];

        if (! is_array($body) || $definition['list'] === 'single') {
            return [];
        }

        $rows = [];
        foreach ($body as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $item[$definition['title']] ?? '';
            if (is_array($title)) {
                $title = $title['rendered'] ?? $title['raw'] ?? '';
            }

            $rows[] = [
                'id'     => $definition['list'] === 'map' ? (string) $key : (string) ($item['id'] ?? ''),
                'slug'   => $item['slug'] ?? '',
                'title'  => html_entity_decode(strip_tags((string) $title)),
                'status' => $item['status'] ?? '',
            ];
        }

        return $rows;
    }
}
