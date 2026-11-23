<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * URL の値の表示（D-113-01）。
 *
 * 画面に URL（http:// または https:// で始まる値）を出すときは、どの画面でもリンクにし、別のタブで開く。
 * 管理の画面（ブログ一覧など）と DB の確認の画面（テーブルの一覧・詳細など）で、出し方をそろえるために使う。
 * URL でない値は、そのまま（エスケープして）出す。
 */
class UrlLink
{
    /**
     * URL の値か（前後に空白を含まない、http:// または https:// で始まる1つの URL だけを URL とみなす）
     */
    public static function is(mixed $value): bool
    {
        return is_string($value) && preg_match('~\Ahttps?://[^\s<>"\'\x00-\x1f]+\z~i', $value) === 1;
    }

    /**
     * 画面に出す HTML（URL ならリンク。$limit を渡すと、見せる文字だけを縮める。リンク先は元の URL のまま）
     */
    public static function html(mixed $value, ?int $limit = null): HtmlString
    {
        $text = $value === null ? '' : (string) $value;
        $shown = $limit !== null ? Str::limit($text, $limit) : $text;

        if (! self::is($value)) {
            return new HtmlString(e($shown));
        }

        return new HtmlString('<a href="' . e($text) . '" target="_blank" rel="noopener">' . e($shown) . '</a>');
    }
}
