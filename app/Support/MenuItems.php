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
     * modal だけの項目は、画面を移らない
     *
     * @var list<array{key: string, code: string, label: string, links: list<array{label: string, route?: string, params?: array, fragment?: string, modal?: string}>}>
     */
    public const GROUPS = [
        ['key' => 'setting', 'code' => 'SETTING', 'label' => '設定', 'links' => [
            // 押すとテーマ切替ポップアップを開く（画面は移らない。D-57）
            ['label' => '画面のテーマ', 'modal' => 'theme-switch-modal'],
        ]],
    ];

    /**
     * 出すメニュー（URL を付ける）
     *
     * @return list<array{key: string, code: string, label: string, links: list<array{label: string, url: string, modal: string|null}>}>
     */
    public static function groups(): array
    {
        return array_map(fn (array $group) => [
            'key'   => $group['key'],
            'code'  => $group['code'],
            'label' => $group['label'],
            'links' => array_map(fn (array $link) => [
                'label' => $link['label'],
                'url'   => isset($link['route']) ? route($link['route'], $link['params'] ?? []) . (isset($link['fragment']) ? '#' . $link['fragment'] : '') : '#',
                'modal' => $link['modal'] ?? null,
            ], $group['links']),
        ], self::GROUPS);
    }
}
