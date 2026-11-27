<?php

namespace App\Support;

/**
 * ポップアップのモード（D-100-01。docs/BLOGOS_UI.md 4-2）
 *
 * ポップアップは、開くときのモード（新規・編集・切替・情報・削除・ゴミ箱へ移動）で、題名の左のアイコンと、送るボタンの名前を変える。
 * 削除のモード（D-120-01）の送るボタンも、ほかのモードと同じ見た目にする（赤枠の btn-danger にはしない）。
 * 同じポップアップを、モードを変えて開くこともある（開く要素に data-modal-mode を付ける。layouts/header の JavaScript）。
 * 題名は partials/modal-title、送るボタンは partials/modal-submit で出す。
 */
final class ModalMode
{
    public const CREATE = 'create';

    public const EDIT = 'edit';

    public const SWITCH = 'switch';

    public const INFO = 'info';

    /** 削除（D-120-01） */
    public const DELETE = 'delete';

    /** ゴミ箱へ移動（D-123-01。WordPress のゴミ箱へ移すだけで、元に戻せる。削除とは分ける） */
    public const TRASH = 'trash';

    /** 全てのモード */
    public const ALL = [self::CREATE, self::EDIT, self::SWITCH, self::INFO, self::DELETE, self::TRASH];

    /** 送るボタンの名前（情報のモードは、送るボタンを出さない） */
    public const SUBMIT_LABELS = [
        self::CREATE => '登録',
        self::EDIT   => '更新',
        self::SWITCH => '切替',
        self::DELETE => '削除',
        self::TRASH  => 'ゴミ箱へ移動',
    ];

    /** アイコンの説明（読み上げ用） */
    public const ICON_LABELS = [
        self::CREATE => '新規',
        self::EDIT   => '編集',
        self::SWITCH => '切替',
        self::INFO   => '情報',
        self::DELETE => '削除',
        self::TRASH  => 'ゴミ箱へ移動',
    ];

    /**
     * 送るボタンの名前。情報のモードは null（ボタンを出さない）
     */
    public static function submitLabel(string $mode): ?string
    {
        self::assertMode($mode);

        return self::SUBMIT_LABELS[$mode] ?? null;
    }

    /**
     * 知らないモードは、書き間違いとしてすぐ分かるように例外にする
     */
    public static function assertMode(string $mode): void
    {
        if (! in_array($mode, self::ALL, true)) {
            throw new \InvalidArgumentException("ポップアップのモードが正しくありません: {$mode}");
        }
    }
}
