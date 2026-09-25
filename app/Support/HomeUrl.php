<?php

namespace App\Support;

/**
 * ホームURL（WordPressのサイトアドレス）の正規化（BLOGOS_DATABASE.md 5-2、D-13-01）。
 */
class HomeUrl
{
    /**
     * 保存する形に整える。ホスト名を小文字にし、末尾のスラッシュ・クエリ・フラグメントを除く。
     * スキーム（http / https）は、WordPressが返したものをそのまま残す。
     */
    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || empty($parts['host'])) {
            return rtrim(trim($url), '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$host}{$port}{$path}";
    }

    /**
     * 同じブログかどうかを比べるためのキー。スキームの違いを吸収し、ホスト名とパスで比べる。
     * 例：http://Example.com/blog/ と https://example.com/blog は同じキーになる。
     */
    public static function comparisonKey(string $url): string
    {
        $normalized = self::normalize($url);

        return preg_replace('#^[a-z]+://#', '', $normalized);
    }
}
