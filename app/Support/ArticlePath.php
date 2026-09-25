<?php

namespace App\Support;

/**
 * 記事の照合に使うパス（posts / pages の normalized_path。D-08-05）。
 *
 * URLからドメイン・末尾のスラッシュ・クエリ・フラグメントを除き、パーセントエンコードを戻したもの。
 * 例：https://example.com/php/%E5%85%A5%E9%96%80/?x=1#a → /php/入門
 */
class ArticlePath
{
    public const MAX_LENGTH = 500;

    public static function fromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH) ?? '/';
        $path = '/' . trim(rawurldecode($path), '/');

        return mb_substr($path, 0, self::MAX_LENGTH);
    }
}
