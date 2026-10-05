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
     * modal だけの項目は、画面を移らない。children は、押すと横に開く、下の階層の項目（同じ形）
     *
     * @var list<array{key: string, code: string, label: string, links: list<array{label: string, route?: string, params?: array, fragment?: string, modal?: string, children?: list<array>}>}>
     */
    public const GROUPS = [
        // 履歴（D-69）。押すと画面を開く（トップページでは、横の画面のパネルに開く）
        ['key' => 'history', 'code' => 'HISTORY', 'label' => '履歴', 'links' => [
            ['label' => 'WordPressとの同期', 'route' => 'database.sync-runs.index'],
        ]],
        ['key' => 'setting', 'code' => 'SETTING', 'label' => '設定', 'links' => [
            // AI の設定（下の階層。D-61）
            ['label' => 'AI', 'children' => [
                // 押すと残高・課金の登録ポップアップを開く（画面は移らない。D-62）
                ['label' => 'OpenAIの画面で見た残高を登録', 'modal' => 'credit-balance-modal'],
                ['label' => '課金した額を登録', 'modal' => 'credit-purchase-modal'],
                // 押すと音声操作ポップアップを開く（画面は移らない）
                ['label' => '音声操作', 'modal' => 'voice-settings-modal'],
            ]],
            // 定期実行の設定（下の階層。項目は ScheduledTasks::MENU。押すと、いつ・有効の設定のポップアップを開く。D-63）
            ['label' => '定期実行', 'children' => 'scheduled-tasks'],
            // 押すとテーマ切替ポップアップを開く（画面は移らない。D-57）
            ['label' => '画面のテーマ', 'modal' => 'theme-switch-modal'],
        ]],
    ];

    /**
     * 出すメニュー（URL を付ける）
     *
     * @return list<array{key: string, code: string, label: string, links: list<array{label: string, url: string, modal: string|null, children: list<array>}>}>
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
     * 項目に URL を付ける（下の階層も）
     */
    protected static function links(array $links): array
    {
        return array_map(fn (array $link) => [
            'label'    => $link['label'],
            'url'      => isset($link['route']) ? route($link['route'], $link['params'] ?? []) . (isset($link['fragment']) ? '#' . $link['fragment'] : '') : '#',
            'modal'    => $link['modal'] ?? null,
            'children' => self::links(($link['children'] ?? []) === 'scheduled-tasks' ? self::scheduledTaskLinks() : ($link['children'] ?? [])),
        ], $links);
    }
}
