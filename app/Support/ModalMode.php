<?php

namespace App\Support;

/**
 * ポップアップのモード（D-100-01。docs/BLOGOS_UI.md 4-2）
 *
 * ポップアップは、開くときのモード（新規・編集・切替・情報）で、題名の左のアイコンと、送るボタンの名前を変える。
 * 同じポップアップを、モードを変えて開くこともある（開く要素に data-modal-mode を付ける。layouts/header の JavaScript）。
 * 題名は partials/modal-title、送るボタンは partials/modal-submit で出す。
 */
final class ModalMode
{
    public const CREATE = 'create';

    public const EDIT = 'edit';

    public const SWITCH = 'switch';

    public const INFO = 'info';

    /** 全てのモード */
    public const ALL = [self::CREATE, self::EDIT, self::SWITCH, self::INFO];

    /** 送るボタンの名前（情報のモードは、送るボタンを出さない） */
    public const SUBMIT_LABELS = [
        self::CREATE => '登録',
        self::EDIT   => '更新',
        self::SWITCH => '切替',
    ];

    /** アイコンの説明（読み上げ用） */
    public const ICON_LABELS = [
        self::CREATE => '新規',
        self::EDIT   => '編集',
        self::SWITCH => '切替',
        self::INFO   => '情報',
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
