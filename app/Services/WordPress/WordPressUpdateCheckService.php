<?php

namespace App\Services\WordPress;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\Blog;
use App\Models\WordPressComponent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WordPress 本体・プラグイン・テーマの更新の確認（D-38）。
 *
 * インストール中のバージョンは WordPress の REST API（プラグイン・テーマ。管理者の権限が必要）と、公開中の RSS の generator（本体）から読み、
 * WordPress.org の公開の API（無料・登録不要）の最新のバージョンと比べる。WordPress.org で公開停止になったプラグインも見つける。
 * 更新は BlogOS からは行わない（WordPress の管理画面で行う）。
 */
class WordPressUpdateCheckService
{
    public const WPORG_PLUGIN = 'https://api.wordpress.org/plugins/info/1.2/';

    public const WPORG_THEME = 'https://api.wordpress.org/themes/info/1.2/';

    public const WPORG_CORE = 'https://api.wordpress.org/core/version-check/1.7/';

    /**
     * @return array{checked: int, updates: int, closed: int}
     *
     * @throws \App\Clients\WordPress\WordPressApiException
     */
    public function check(Blog $blog): array
    {
        $client = WordPressApiClient::forBlog($blog);
        $components = [];

        foreach ((array) $client->getOrFail('/wp-json/wp/v2/plugins', ['context' => 'edit'])->json() as $plugin) {
            $slug = (string) ($plugin['plugin'] ?? '');
            $info = $this->wporg(self::WPORG_PLUGIN, 'plugin_information', explode('/', $slug)[0]);
            $components[] = ['type' => 'plugin', 'slug' => $slug, 'name' => strip_tags((string) ($plugin['name'] ?? $slug)), 'status' => $plugin['status'] ?? null,
                'installed_version' => $plugin['version'] ?? null] + $info;
        }

        foreach ((array) $client->getOrFail('/wp-json/wp/v2/themes', ['context' => 'edit'])->json() as $theme) {
            $slug = (string) ($theme['stylesheet'] ?? '');
            $info = $this->wporg(self::WPORG_THEME, 'theme_information', $slug);
            $name = $theme['name']['raw'] ?? $theme['name']['rendered'] ?? $theme['name'] ?? $slug;
            $components[] = ['type' => 'theme', 'slug' => $slug, 'name' => strip_tags((string) $name), 'status' => $theme['status'] ?? null,
                'installed_version' => $theme['version'] ?? null] + $info;
        }

        $components[] = ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'status' => null, 'installed_version' => $this->coreVersion($blog)] + $this->latestCore();

        $keep = [];
        foreach ($components as &$component) {
            $component['update_available'] = filled($component['installed_version']) && filled($component['latest_version'])
                && version_compare((string) $component['latest_version'], (string) $component['installed_version'], '>');
            $row = WordPressComponent::updateOrCreate(
                ['blog_id' => $blog->id, 'type' => $component['type'], 'slug' => $component['slug']],
                array_diff_key($component, array_flip(['type', 'slug'])) + ['checked_at' => now()]
            );
            $keep[] = $row->id;
        }
        unset($component);

        // 削除されたプラグイン・テーマは消す
        WordPressComponent::where('blog_id', $blog->id)->whereNotIn('id', $keep)->delete();

        return [
            'checked' => count($components),
            'updates' => count(array_filter($components, fn ($c) => $c['update_available'])),
            'closed'  => count(array_filter($components, fn ($c) => $c['wporg_state'] === 'closed')),
        ];
    }

    /**
     * @return array{wporg_state: string|null, latest_version: string|null, requires_php: string|null, requires_wp: string|null, wporg_note: string|null}
     */
    protected function wporg(string $url, string $action, string $slug): array
    {
        $empty = ['wporg_state' => null, 'latest_version' => null, 'requires_php' => null, 'requires_wp' => null, 'wporg_note' => null];
        if ($slug === '') {
            return $empty;
        }

        try {
            $data = Http::timeout(20)->get($url, ['action' => $action, 'request' => ['slug' => $slug, 'fields' => ['sections' => false, 'versions' => false]]])->json();
        } catch (ConnectionException) {
            return $empty;
        }

        if (($data['error'] ?? null) === 'closed' || ($data['closed'] ?? false)) {
            return ['wporg_state' => 'closed', 'wporg_note' => trim(($data['closed_date'] ?? '') . ' ' . ($data['reason_text'] ?? ''))] + $empty;
        }
        if (isset($data['error']) || ! isset($data['version'])) {
            return ['wporg_state' => 'not_found'] + $empty;
        }

        return [
            'wporg_state'    => 'available',
            'latest_version' => (string) $data['version'],
            'requires_php'   => filled($data['requires_php'] ?? null) ? (string) $data['requires_php'] : null,
            'requires_wp'    => filled($data['requires'] ?? null) ? (string) $data['requires'] : null,
            'wporg_note'     => null,
        ];
    }

    /**
     * WordPress 本体のバージョン（公開中の RSS の generator。読めなければ null）
     */
    protected function coreVersion(Blog $blog): ?string
    {
        try {
            $feed = Http::timeout(20)->get(rtrim($blog->home, '/') . '/feed/')->body();
        } catch (ConnectionException) {
            return null;
        }

        return preg_match('#<generator>https?://wordpress\.org/\?v=([\d.]+)</generator>#', $feed, $m) ? $m[1] : null;
    }

    /**
     * @return array{wporg_state: string|null, latest_version: string|null, requires_php: string|null, requires_wp: string|null, wporg_note: string|null}
     */
    protected function latestCore(): array
    {
        try {
            $offer = Http::timeout(20)->get(self::WPORG_CORE)->json('offers.0');
        } catch (ConnectionException) {
            $offer = null;
        }

        return ['wporg_state' => $offer ? 'available' : null, 'latest_version' => $offer['current'] ?? null, 'requires_php' => $offer['php_version'] ?? null, 'requires_wp' => null, 'wporg_note' => null];
    }
}
