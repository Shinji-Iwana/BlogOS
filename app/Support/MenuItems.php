<?php

namespace App\Support;

/**
 * ヘッダーの下のメニューバー（D-56）。
 *
 * ログイン後の全画面のヘッダーの下に出し、どの画面からでも開けるようにする。
 * 種類（英字の札と名前）ごとに、押すと項目が開く。新しい項目は、ここに1行を加える。
 */
class MenuItems
{
    /**
     * route（と params・fragment）は開く画面。modal は、押したときに画面を移らずに開くポップアップの id（layouts/header）。
     * modal だけの項目は、画面を移らない。children は、押すと横に開く、下の階層の項目（同じ形）。
     * post（と post_params・confirm）は、押すと確認してから、その処理を送る（画面は移らず、開いていた画面に戻る。例：即時実行。D-63-09）
     *
     * @var list<array{key: string, code: string, label: string, links: list<array{label: string, route?: string, params?: array, fragment?: string, modal?: string, children?: list<array>}>}>
     */
    public const GROUPS = [
        // 履歴（D-69。項目の名前は画面の名前と同じ。D-63-15。順は D-63-26）。押すと画面を開く（トップページでは、横の画面のパネルに開く）
        ['key' => 'history', 'code' => 'HISTORY', 'label' => '履歴', 'links' => [
            // ログイン履歴（D-63-13。以前は設定の画面の「セキュリティ」から開いた）
            ['label' => 'ログイン履歴', 'route' => 'database.login-histories.index'],
            // ブログ情報の同期履歴（D-63-16。以前はトップページの「管理」の「ブログ変更履歴一覧」から開いた）
            ['label' => 'ブログ情報の同期履歴', 'route' => 'database-blog-history-list'],
            ['label' => 'WordPressとの同期履歴', 'route' => 'database.sync-runs.index'],
            // Googleとの同期履歴（D-63-19。以前は画面「Google連携」の取得の記録）
            ['label' => 'Googleとの同期履歴', 'route' => 'google.fetch-runs.index'],
            // OpenAI API料金表との同期履歴（D-63-27。以前はAIの設定の画面の「料金表の変更の記録」）
            ['label' => 'OpenAI API料金表との同期履歴', 'route' => 'ai.prices.history'],
            ['label' => '定期実行履歴', 'route' => 'scheduled-tasks.runs'],
        ]],
        // 設定（順は D-63-26）
        ['key' => 'setting', 'code' => 'SETTING', 'label' => '設定', 'links' => [
            // 押すとテーマ切替ポップアップを開く（画面は移らない。D-57）
            ['label' => '画面のテーマ', 'modal' => 'theme-switch-modal'],
            // ブログ（下の階層。押すとポップアップを開く。D-63-22）
            ['label' => 'ブログ', 'children' => [
                // ブログの登録（以前は画面「ブログ登録」。D-63-21）
                ['label' => 'ブログを登録', 'modal' => 'blog-register-modal'],
                // 選択中のブログの認証情報（以前は画面「認証情報」）
                ['label' => '認証情報', 'modal' => 'blog-credential-modal'],
            ]],
            // Google連携（下の階層。押すとポップアップを開く。以前は画面「Google連携」。D-63-19）
            ['label' => 'Google', 'children' => [
                ['label' => 'アカウント', 'modal' => 'google-account-modal'],
                ['label' => 'Google Analytics 4', 'modal' => 'google-ga4-modal'],
                ['label' => 'Search Console', 'modal' => 'google-search-console-modal'],
                ['label' => 'AdSense', 'modal' => 'google-adsense-modal'],
            ]],
            // OpenAI の設定（下の階層。D-61。名前は D-63-24）
            ['label' => 'OpenAI', 'children' => [
                // 押すと残高・課金の登録ポップアップを開く（画面は移らない。D-62）
                ['label' => 'OpenAIの画面で見た残高を登録', 'modal' => 'credit-balance-modal'],
                ['label' => '課金額を登録', 'modal' => 'credit-purchase-modal'],
                // 押すと音声操作ポップアップを開く（画面は移らない）
                ['label' => '音声操作', 'modal' => 'voice-settings-modal'],
            ]],
            // 押すとアフィリエイト提携先の登録ポップアップを開く（以前は画面「アフィリエイトのプログラム」の欄。D-63-20）
            ['label' => 'アフィリエイト提携先を登録', 'modal' => 'affiliate-program-modal'],
            // 即時実行（下の階層。項目は ScheduledTasks::runNowKeys()。押すと、確認してから定期実行を今すぐ Queue に登録する。D-63-09）
            ['label' => '即時実行', 'children' => 'run-now'],
            // 定期実行の設定（下の階層。項目は ScheduledTasks::MENU。押すと、いつ・有効の設定のポップアップを開く。D-63）
            ['label' => '定期実行', 'children' => 'scheduled-tasks'],
        ]],
        // 情報（D-63-11。項目の名前は画面の名前と同じ。D-63-15）。押すと画面を開く（トップページでは、横の画面のパネルに開く）
        ['key' => 'info', 'code' => 'INFO', 'label' => '情報', 'links' => [
            // XServer情報（D-71。以前の名前は「サーバー情報」。サーバーの基本情報・DB・古い記録の削除・キュー・ファイルの容量）
            ['label' => 'XServer情報', 'route' => 'server.index'],
            ['label' => 'WordPress情報', 'route' => 'wordpress-updates.index'],
            // WordPress API情報（D-63-12。以前は設定の画面とトップページの「管理」から開いた）
            ['label' => 'WordPress API情報', 'route' => 'wp-api.home'],
            // OpenAI API料金表情報（D-63-28。以前はAIの設定の画面の「API実行の料金表」）
            ['label' => 'OpenAI API料金表情報', 'route' => 'ai.prices.index'],
        ]],
    ];

    /**
     * 出すメニュー（URL を付ける）
     *
     * @return list<array{key: string, code: string, label: string, links: list<array{label: string, url: string, modal: string|null, post: string|null, confirm: string|null, children: list<array>}>}>
     */
    public static function groups(): array
    {
        return array_map(fn (array $group) => [
            'key'   => $group['key'],
            'code'  => $group['code'],
            'label' => $group['label'],
            'links' => self::links($group['links']),
        ], self::GROUPS);
    }

    /**
     * 「定期実行」の下の項目（App\Support\ScheduledTasks::MENU）
     */
    protected static function scheduledTaskLinks(): array
    {
        return array_map(fn (string $key) => ['label' => ScheduledTasks::menuLabel($key), 'modal' => ScheduledTasks::modalId($key)], ScheduledTasks::MENU);
    }

    /**
     * 「即時実行」の下の項目（App\Support\ScheduledTasks::runNowKeys()。定期実行の今すぐ実行を送る）
     */
    protected static function runNowLinks(): array
    {
        return array_map(fn (string $key) => [
            'label'       => ScheduledTasks::menuLabel($key),
            'post'        => 'scheduled-tasks.run',
            'post_params' => ['key' => $key],
            'confirm'     => '「' . ScheduledTasks::menuLabel($key) . '」を今すぐ実行しますか？',
        ], ScheduledTasks::runNowKeys());
    }

    /**
     * 項目に URL を付ける（下の階層も）
     */
    protected static function links(array $links): array
    {
        return array_map(fn (array $link) => [
            'label'    => $link['label'],
            'url'      => isset($link['route']) ? route($link['route'], $link['params'] ?? []) . (isset($link['fragment']) ? '#' . $link['fragment'] : '') : '#',
            'modal'    => $link['modal'] ?? null,
            'post'     => isset($link['post']) ? route($link['post'], $link['post_params'] ?? []) : null,
            'confirm'  => $link['confirm'] ?? null,
            'children' => self::links(match ($link['children'] ?? []) {
                'scheduled-tasks' => self::scheduledTaskLinks(),
                'run-now'         => self::runNowLinks(),
                default           => $link['children'] ?? [],
            }),
        ], $links);
    }
}
