<?php

namespace App\Support;

/**
 * トップページの、各画面への入口（D-49-07）。
 *
 * どのテーマでも、この一覧から入口を出す（blank は「種類：リンク・リンク」の行、ironman は種類ごとのパネル）。
 * 新しい画面を作ったら、ここに1行を加える。
 */
class DashboardLinks
{
    /**
     * 種類ごとの入口。blog が true の入口は、ブログを選んでいるときだけ出す
     *
     * @return list<array{key: string, code: string, label: string, links: list<array{label: string, route: string, params?: array, blog: bool}>}>
     */
    public const GROUPS = [
        ['key' => 'articles', 'code' => 'ARTICLES', 'label' => '記事', 'links' => [
            ['label' => '投稿', 'route' => 'articles.index', 'params' => ['type' => 'posts'], 'blog' => true],
            ['label' => '固定ページ', 'route' => 'articles.index', 'params' => ['type' => 'pages'], 'blog' => true],
            ['label' => '編集案', 'route' => 'drafts.index', 'blog' => true],
            ['label' => '反映記録', 'route' => 'push-operations.index', 'blog' => true],
            ['label' => '記事の企画', 'route' => 'topics.index', 'blog' => true],
            ['label' => 'カテゴリ', 'route' => 'categories.index', 'blog' => true],
            ['label' => 'カテゴリの立ち上げ', 'route' => 'launches.index', 'blog' => true],
            ['label' => 'タイトル・メタディスクリプションの改善の候補', 'route' => 'articles.titles', 'blog' => true],
            ['label' => '内部リンクの確認', 'route' => 'links.check', 'blog' => true],
            ['label' => '管理情報の案の確認', 'route' => 'management-suggestions.index', 'blog' => true],
        ]],
        ['key' => 'ai', 'code' => 'AI', 'label' => 'AI', 'links' => [
            ['label' => 'AIで新規記事の案を作る', 'route' => 'ai.generations.create', 'params' => ['mode' => 'new_article'], 'blog' => true],
            ['label' => 'AIのまとめて実行', 'route' => 'ai.batches.index', 'blog' => true],
            ['label' => 'AI実行記録', 'route' => 'ai.generations.index', 'blog' => true],
            ['label' => 'AIの設定', 'route' => 'ai.settings.edit', 'blog' => true],
            ['label' => 'AIの費用と残高', 'route' => 'ai.credits.index', 'blog' => true],
        ]],
        ['key' => 'revenue', 'code' => 'REVENUE', 'label' => '収益', 'links' => [
            ['label' => '教材（書籍・Udemy・スクール・問題集）', 'route' => 'materials.index', 'blog' => true],
            ['label' => 'アフィリエイトのプログラム', 'route' => 'materials.programs.index', 'blog' => true],
            ['label' => '教材の案の確認', 'route' => 'materials.suggestions.index', 'blog' => true],
            ['label' => '記事の教材の見直し', 'route' => 'materials.reviews.index', 'blog' => true],
        ]],
        ['key' => 'images', 'code' => 'IMAGES', 'label' => '画像', 'links' => [
            ['label' => '画像（図解・イラスト・アイキャッチ・スクリーンショット）', 'route' => 'images.index', 'blog' => true],
            ['label' => 'カテゴリごとのアイキャッチ', 'route' => 'images.eyecatches', 'blog' => true],
        ]],
        ['key' => 'analytics', 'code' => 'ANALYTICS', 'label' => '分析', 'links' => [
            ['label' => '分析（GA4・Search Console・AdSense）', 'route' => 'analytics.index', 'blog' => true],
            ['label' => '記事の実績と次にやること', 'route' => 'analytics.performance', 'blog' => true],
            ['label' => 'AdSense（推定収益額・残高・広告ユニット）', 'route' => 'analytics.adsense', 'blog' => true],
            ['label' => 'インデックスの登録状態', 'route' => 'google.index-status', 'blog' => true],
            // Google連携の設定は、メニューの「設定 → Google」のポップアップ（D-63-19）
        ]],
        ['key' => 'system', 'code' => 'SYSTEM', 'label' => '管理', 'links' => [
            // 画面「設定」はなくした（D-63-23。以前はヘッダーの「BlogOS」の横にあった。D-60）
            // WordPress情報はメニューの「情報 → WordPress情報」、定期実行履歴は「履歴 → 定期実行履歴」から開く（D-63-11）。WordPress API情報は「情報 → WordPress API情報」（D-63-12）、ブログ情報の同期履歴は「履歴 → ブログ情報の同期履歴」（D-63-16）
            // ブログの登録は、メニューの「設定 → ブログを登録」のポップアップ（D-63-21）
            ['label' => 'ブログ一覧', 'route' => 'database-blog-list', 'blog' => false],
            ['label' => '取り込んだWordPressのデータ（DB確認）', 'route' => 'database.wordpress-records.tables', 'blog' => true],
            ['label' => 'サイト内検索', 'route' => 'api-site-search', 'blog' => true],
        ]],
    ];

    /**
     * 出す入口（URL を付ける。ブログを選んでいなければ、ブログが要らない入口だけ。空の種類は出さない）
     *
     * @return list<array{key: string, code: string, label: string, links: list<array{label: string, url: string}>}>
     */
    public static function groups(bool $hasSelectedBlog): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $links = [];
            foreach ($group['links'] as $link) {
                if ($link['blog'] && ! $hasSelectedBlog) {
                    continue;
                }
                $links[] = ['label' => $link['label'], 'url' => route($link['route'], $link['params'] ?? [])];
            }
            if ($links !== []) {
                $groups[] = ['key' => $group['key'], 'code' => $group['code'], 'label' => $group['label'], 'links' => $links];
            }
        }

        return $groups;
    }
}
