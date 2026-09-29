<?php

namespace App\Support;

/**
 * 記事の本文の BlogOS の目印（html-rules.md 5章。D-34）。
 *
 * AI は、教材のリンク・記事へのリンク・画像の HTML を書かず、目印を書く。BlogOS が登録した内容に置き換える。
 * - [[教材:ID]]：教材（materials.id）の紹介リンク
 * - [[記事:ID]]：記事（WordPress の ID。投稿と固定ページで ID は重ならない）へのリンク
 * - [[画像:ID]]：画像（images.id）。AI が新しく依頼する画像は [[画像:新規1]] のように書き、BlogOS が ID に置き換える
 */
class ArticlePlaceholders
{
    public const PATTERN = '/\[\[(教材|記事|画像):([^\]\s]{1,30})\]\]/u';

    /**
     * 本文に残っている目印
     *
     * @return list<string> 例：[[画像:5]]
     */
    public static function remaining(?string $content): array
    {
        preg_match_all(self::PATTERN, (string) $content, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * 目印を置き換える。置き換えられなかった目印は、そのまま残す
     *
     * @param callable(string $type, string $id): ?string $resolve 目印の種類と ID から HTML を返す（置き換えられなければ null）
     */
    public static function replace(string $content, callable $resolve): string
    {
        // 目印だけの段落は、段落ごと置き換える（リンクの枠・画像を <p> の中に入れないため）
        $content = preg_replace_callback('/<p>\s*(\[\[(教材|記事|画像):([^\]\s]{1,30})\]\])\s*<\/p>/u', function ($m) use ($resolve) {
            $html = $resolve($m[2], $m[3]);

            return $html === null ? $m[0] : ($m[2] === '記事' ? "<p>{$html}</p>" : $html);
        }, $content) ?? $content;

        return preg_replace_callback(self::PATTERN, fn ($m) => $resolve($m[1], $m[2]) ?? $m[0], $content) ?? $content;
    }
}
