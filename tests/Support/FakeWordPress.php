<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * テスト用の偽のWordPress（REST API）。
 *
 * 各リソースの項目を配列で持ち、一覧・_fields・include・ページ送りに応じた応答を返す。
 * 受け取った要求は $requests に残す。
 */
class FakeWordPress
{
    public string $home;

    /** @var array<string, array<int, array>> endpoint名（posts 等）=> 項目の一覧 */
    public array $lists = [];

    /** @var array<string, array<string, array>> endpoint名（statuses 等）=> slug をキーにした定義 */
    public array $definitions = [];

    public array $settings = ['title' => 'Example Blog', 'description' => ''];

    /** @var array<string, int> endpoint名 => 返すHTTPステータス（失敗させる場合） */
    public array $failures = [];

    /** @var array<int, array{path: string, query: array}> */
    public array $requests = [];

    public function __construct(string $home = 'https://blog.example.test')
    {
        $this->home = $home;

        $this->definitions = [
            'statuses'   => ['publish' => ['name' => '公開済み', 'public' => true, 'queryable' => true, 'show_in_list' => true]],
            'types'      => ['post' => ['name' => '投稿', 'description' => '', 'hierarchical' => false, 'rest_base' => 'posts', 'rest_namespace' => 'wp/v2', 'taxonomies' => ['category', 'post_tag']]],
            'taxonomies' => ['category' => ['name' => 'カテゴリー', 'description' => '', 'hierarchical' => true, 'rest_base' => 'categories', 'rest_namespace' => 'wp/v2', 'types' => ['post']]],
        ];

        // カスタム投稿タイプ等を使うテストでは、同じ形でキー（rest_base）を追加する
        foreach (['users', 'categories', 'tags', 'media', 'pages', 'posts'] as $name) {
            $this->lists[$name] = [];
        }
    }

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->respond($request));

        return $this;
    }

    /**
     * 指定したendpointへの要求を返す
     *
     * @return array<int, array> クエリの一覧
     */
    public function requestsTo(string $name): array
    {
        return array_values(array_map(
            fn ($r) => $r['query'],
            array_filter($this->requests, fn ($r) => $r['path'] === "/wp-json/wp/v2/{$name}")
        ));
    }

    protected function respond(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH) ?? '/';
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->requests[] = ['path' => $path, 'query' => $query];

        if ($path === '/wp-json/' || $path === '/wp-json') {
            return Http::response([
                'home'            => $this->home,
                'namespaces'      => ['wp/v2'],
                'timezone_string' => 'Asia/Tokyo',
                'gmt_offset'      => 9,
            ]);
        }

        $name = str_replace('/wp-json/wp/v2/', '', $path);

        // 1件の取得・作成・更新・削除（/posts/123 など）
        if (preg_match('#^(posts|pages|categories|tags|media)(?:/(\d+))?$#', $name, $matches) && ($request->method() !== 'GET' || isset($matches[2]))) {
            return $this->respondItem($request, $matches[1], isset($matches[2]) ? (int) $matches[2] : null, $query);
        }

        if (isset($this->failures[$name])) {
            return Http::response(['code' => 'error', 'message' => "{$name} failed"], $this->failures[$name]);
        }

        if ($name === 'settings') {
            return Http::response($this->settings);
        }

        if (isset($this->definitions[$name])) {
            return Http::response($this->definitions[$name]);
        }

        if (! isset($this->lists[$name])) {
            return Http::response(['code' => 'rest_no_route'], 404);
        }

        $items = $this->lists[$name];

        if (isset($query['include'])) {
            $ids = array_map('intval', explode(',', $query['include']));
            $items = array_values(array_filter($items, fn ($item) => in_array($item['id'], $ids, true)));
        }

        if (isset($query['_fields'])) {
            $fields = array_flip(explode(',', $query['_fields']));
            $items = array_map(fn ($item) => array_intersect_key($item, $fields), $items);
        }

        $perPage = (int) ($query['per_page'] ?? 10);
        $page = (int) ($query['page'] ?? 1);
        $totalPages = max(1, (int) ceil(count($items) / $perPage));

        return Http::response(
            array_values(array_slice($items, ($page - 1) * $perPage, $perPage)),
            200,
            ['X-WP-Total' => (string) count($items), 'X-WP-TotalPages' => (string) $totalPages]
        );
    }

    /**
     * 書き込み（POST・DELETE）の失敗のさせ方。'timeout' で通信エラー、数値でそのHTTPステータスを返す
     */
    public string|int|null $writeFailure = null;

    /** 書き込みのたびに進める、更新日時の元 */
    protected int $tick = 0;

    protected function respondItem(Request $request, string $name, ?int $id, array $query)
    {
        $method = $request->method();

        if ($method !== 'GET' && $this->writeFailure !== null) {
            if ($this->writeFailure === 'timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['code' => 'error', 'message' => 'write failed'], (int) $this->writeFailure);
        }

        $index = $id === null ? null : array_search($id, array_column($this->lists[$name], 'id'), true);

        if ($id !== null && $index === false) {
            return Http::response(['code' => 'rest_post_invalid_id'], 404);
        }

        if ($method === 'GET') {
            $item = $this->lists[$name][$index];
            if (isset($query['_fields'])) {
                $item = array_intersect_key($item, array_flip(explode(',', $query['_fields'])));
            }

            return Http::response($item);
        }

        $modified = sprintf('2026-10-%02dT00:%02d:00', intdiv($this->tick, 60) + 1, ++$this->tick % 60);

        if ($method === 'DELETE') {
            $item = $this->lists[$name][$index];
            if (filter_var($request->data()['force'] ?? $query['force'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                array_splice($this->lists[$name], $index, 1);

                return Http::response(['deleted' => true, 'previous' => $item]);
            }

            $item['status'] = 'trash';
            $item['modified_gmt'] = $modified;
            $this->lists[$name][$index] = $item;

            return Http::response($item);
        }

        // POST（作成・更新）
        $data = $request->data();
        if ($id === null) {
            $newId = max(array_merge([1000], array_column($this->lists['posts'], 'id'), array_column($this->lists['pages'], 'id'))) + 1;
            $item = $name === 'posts' ? self::post($newId) : self::page($newId);
            $item['status'] = 'draft';
        } else {
            $item = $this->lists[$name][$index];
        }

        // raw と rendered を持つ項目（カテゴリ・タグの description は文字列）
        $rawFields = match ($name) {
            'media'              => ['title', 'caption', 'description'],
            'categories', 'tags' => [],
            default              => ['title', 'content', 'excerpt'],
        };
        foreach ($data as $field => $value) {
            $item[$field] = in_array($field, $rawFields, true) ? ['raw' => $value, 'rendered' => $value] : $value;
        }
        if (! in_array($name, ['categories', 'tags'], true)) {
            $item['modified_gmt'] = $modified;
            $item['modified'] = $modified;
        }

        // AIOSEO：設定した説明が出力される。空にすると自動の説明に戻る
        if (isset($data['aioseo_meta_data'])) {
            $description = $data['aioseo_meta_data']['description'] ?? '';
            $item['aioseo_meta_data'] = ['description' => $description === '' ? null : $description];
            $item['aioseo_head_json'] = ['description' => $description === '' ? '自動の説明' : $description];
        }

        if ($id === null) {
            $this->lists[$name][] = $item;
        } else {
            $this->lists[$name][$index] = $item;
        }

        return Http::response($item, $id === null ? 201 : 200);
    }

    /*
     * 項目を作る補助
     */

    public static function post(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'             => $id,
            'date'           => '2026-09-01T12:00:00',
            'date_gmt'       => '2026-09-01T03:00:00',
            'modified'       => '2026-09-01T12:00:00',
            'modified_gmt'   => '2026-09-01T03:00:00',
            'slug'           => "post-{$id}",
            'status'         => 'publish',
            'type'           => 'post',
            'link'           => "https://blog.example.test/post-{$id}/",
            'title'          => ['raw' => "Title {$id}", 'rendered' => "Title {$id}"],
            'content'        => ['raw' => "Body {$id}", 'rendered' => "<p>Body {$id}</p>"],
            'excerpt'        => ['raw' => '', 'rendered' => ''],
            'author'         => 1,
            'featured_media' => 0,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'sticky'         => false,
            'template'       => '',
            'format'         => 'standard',
            'categories'     => [],
            'tags'           => [],
            // SEOプラグイン（AIOSEO）が加える項目。説明を設定していない場合は、本文から自動で作る
            'aioseo_meta_data' => ['description' => null],
            'aioseo_head_json' => ['description' => "Body {$id} の自動の説明"],
        ], $overrides);
    }

    public static function page(int $id, array $overrides = []): array
    {
        $page = self::post($id, array_merge(['type' => 'page', 'slug' => "page-{$id}", 'link' => "https://blog.example.test/page-{$id}/", 'parent' => 0, 'menu_order' => 0], $overrides));
        unset($page['categories'], $page['tags'], $page['sticky'], $page['format']);

        return $page;
    }

    public static function media(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'            => $id,
            'date'          => '2026-09-01T12:00:00',
            'date_gmt'      => '2026-09-01T03:00:00',
            'modified'      => '2026-09-01T12:00:00',
            'modified_gmt'  => '2026-09-01T03:00:00',
            'slug'          => "media-{$id}",
            'status'        => 'inherit',
            'link'          => "https://blog.example.test/media-{$id}/",
            'title'         => ['raw' => "Media {$id}", 'rendered' => "Media {$id}"],
            'caption'       => ['raw' => '', 'rendered' => ''],
            'description'   => ['raw' => '', 'rendered' => ''],
            'alt_text'      => '',
            'author'        => 1,
            'post'          => 0,
            'source_url'    => "https://blog.example.test/wp-content/uploads/{$id}.png",
            'mime_type'     => 'image/png',
            'media_type'    => 'image',
            'media_details' => ['width' => 100, 'height' => 100, 'filesize' => 1000, 'sizes' => []],
        ], $overrides);
    }

    public static function term(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'          => $id,
            'name'        => "Term {$id}",
            'slug'        => "term-{$id}",
            'description' => '',
            'link'        => "https://blog.example.test/term-{$id}/",
            'parent'      => 0,
        ], $overrides);
    }

    public static function user(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'          => $id,
            'name'        => "User {$id}",
            'slug'        => "user-{$id}",
            'url'         => '',
            'description' => '',
            'link'        => "https://blog.example.test/author/user-{$id}/",
            'avatar_urls' => [],
            'roles'       => ['administrator'],
        ], $overrides);
    }
}
