<?php

namespace App\Support;

/**
 * スラッグの表示と比較（D-29）。
 *
 * WordPress は日本語のスラッグを、URL用に符号化した形（例：%e3%83%87%e3%83%bc%e3%82%bf）で保存し、APIでもその形で返す。
 * BlogOS は取り込んだ値をそのまま保存し（同期の差分の判定のため）、画面では読める形に戻して表示する。
 * 編集案では読める形で扱い、WordPress に送ると WordPress が符号化する。比べるときは、どちらの形でも同じとみなす。
 */
class Slug
{
    /**
     * 画面に表示する形（符号化されていれば、読める形に戻す）
     */
    public static function display(?string $slug): ?string
    {
        if ($slug === null || ! str_contains($slug, '%')) {
            return $slug;
        }

        $decoded = rawurldecode($slug);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $slug;
    }

    /**
     * 同じスラッグか（符号化した形と読める形、大文字と小文字の違いを区別しない）
     */
    public static function same(?string $a, ?string $b): bool
    {
        return self::key($a) === self::key($b);
    }

    protected static function key(?string $slug): ?string
    {
        return $slug === null ? null : mb_strtolower((string) self::display($slug));
    }
}
