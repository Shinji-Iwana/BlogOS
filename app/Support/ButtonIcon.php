<?php

namespace App\Support;

/**
 * ボタンの名前の左に付けるアイコン（D-121-01。docs/BLOGOS_UI.md 4-4）。
 *
 * 押すと何が変わるかを、アイコンで見分けられるようにする。
 * * wordpress：WordPress を変える（反映が成功すると、BlogOS の DB も合わせて更新する）
 * * blogos：BlogOS の中だけを変える（WordPress は変えない）。アイコンはアークリアクター
 * どちらも変えないボタン（絞り込み・ログイン・確認の画面を開くだけなど）には付けない。
 * 色はボタンの文字の色（currentColor）。部品は partials/button-icon。
 */
final class ButtonIcon
{
    public const WORDPRESS = 'wordpress';

    public const BLOGOS = 'blogos';

    public const ALL = [self::WORDPRESS, self::BLOGOS];

    /**
     * マウスを乗せたときのツールチップ
     */
    public const LABELS = [
        self::WORDPRESS => 'WordPress を変える（BlogOS も合わせて更新する）',
        self::BLOGOS    => 'BlogOS の中だけを変える（WordPress は変えない）',
    ];

    /**
     * 1つのページに同じアイコンが何度も出るため、WordPress のアイコンの切り抜き（mask）の id を、1つずつ変える
     */
    private static int $sequence = 0;

    public static function label(string $to): string
    {
        if (! in_array($to, self::ALL, true)) {
            throw new \InvalidArgumentException("ボタンのアイコンの種類が正しくありません：{$to}");
        }

        return self::LABELS[$to];
    }

    public static function nextId(): string
    {
        return 'button-icon-' . (++self::$sequence);
    }
}
