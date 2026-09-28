<?php

namespace App\Support;

/**
 * 教材のリンク（アフィリエイト・商品ページ）の読み取り（D-30）。
 *
 * 同じ教材のリンクは、書き方（http/https、末尾の / など）や経由するサービス（もしも）が違っても、
 * 同じ識別子（key）になるようにする。記事の本文にあるリンクと、登録した教材との照合に使う。
 */
class AffiliateLink
{
    /**
     * 入力（URL、または <a href="..."> を含むHTML）からURLを取り出す
     */
    public static function extractUrl(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        if (str_contains($input, '<')) {
            if (! preg_match('/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/is', $input, $matches)) {
                return null;
            }
            $input = html_entity_decode(trim($matches[2]), ENT_QUOTES | ENT_HTML5);
        }

        return str_starts_with($input, '//') ? "https:{$input}" : $input;
    }

    /**
     * 照合に使う識別子。読み取れないURLは null
     */
    public static function key(?string $url): ?string
    {
        $url = self::extractUrl($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        // もしも：遷移先のURL（url）があればその商品で、なければ提携先の広告（p_id・pc_id・pl_id）で識別する
        if ($host === 'af.moshimo.com') {
            if (filled($query['url'] ?? null) && is_string($query['url'])) {
                return self::key($query['url']);
            }

            return 'moshimo:' . implode('-', [$query['p_id'] ?? '', $query['pc_id'] ?? '', $query['pl_id'] ?? '']);
        }

        // 楽天アフィリエイト（直接）：遷移先のURL（pc）
        if ($host === 'hb.afl.rakuten.co.jp' && filled($query['pc'] ?? null) && is_string($query['pc'])) {
            return self::key($query['pc']);
        }

        if (($asin = self::asin($url)) !== null) {
            return "amazon:{$asin}";
        }
        if (($bookId = self::rakutenBookId($url)) !== null) {
            return "rakuten-books:{$bookId}";
        }
        if ($host === 'trk.udemy.com') {
            return 'udemy-trk:' . trim($path, '/');
        }
        if (preg_match('#(^|\.)udemy\.com$#', $host) && preg_match('#^/course/([^/]+)#', $path, $matches)) {
            return "udemy-course:{$matches[1]}";
        }

        return 'url:' . preg_replace('/^www\./', '', $host) . rtrim($path, '/');
    }

    /**
     * Amazon の商品ページ（/dp/ASIN、/gp/product/ASIN）の ASIN
     */
    public static function asin(?string $url): ?string
    {
        $url = self::innerUrl($url);
        if ($url === null || ! preg_match('#^https?://(www\.)?amazon\.co\.jp/#i', $url)) {
            return null;
        }

        return preg_match('#/(?:dp|gp/product|exec/obidos/ASIN)/([A-Z0-9]{10})#i', $url, $matches) ? strtoupper($matches[1]) : null;
    }

    /**
     * 楽天ブックスの商品ページ（books.rakuten.co.jp/rb/番号/）の番号
     */
    public static function rakutenBookId(?string $url): ?string
    {
        $url = self::innerUrl($url);

        return $url !== null && preg_match('#^https?://books\.rakuten\.co\.jp/rb/(\d+)#i', $url, $matches) ? $matches[1] : null;
    }

    /**
     * Amazon の商品ページ（https://www.amazon.co.jp/dp/ASIN）。アフィリエイトのリンク（もしも経由を含む）からも作る
     */
    public static function amazonProductUrl(?string $url): ?string
    {
        $asin = self::asin($url);

        return $asin !== null ? "https://www.amazon.co.jp/dp/{$asin}" : null;
    }

    /**
     * 楽天ブックスの商品ページ（https://books.rakuten.co.jp/rb/番号/）。アフィリエイトのリンク（もしも経由を含む）からも作る
     */
    public static function rakutenProductUrl(?string $url): ?string
    {
        $bookId = self::rakutenBookId($url);

        return $bookId !== null ? "https://books.rakuten.co.jp/rb/{$bookId}/" : null;
    }

    /**
     * 書籍の ASIN が ISBN-10 のとき、ISBN-13 にする（書籍以外の ASIN は null）
     */
    public static function isbn13FromAsin(?string $asin): ?string
    {
        if ($asin === null || ! preg_match('/^\d{9}[\dX]$/', $asin)) {
            return null;
        }

        $body = '978' . substr($asin, 0, 9);
        $sum = 0;
        foreach (str_split($body) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $body . ((10 - $sum % 10) % 10);
    }

    /**
     * アフィリエイトのサービスを経由するリンクの遷移先（商品ページ）。遷移先が入っていないリンクは null
     */
    public static function landingUrl(?string $url): ?string
    {
        $url = self::extractUrl($url);
        $inner = self::innerUrl($url);

        return $inner !== null && $inner !== $url ? $inner : null;
    }

    /**
     * もしも・楽天アフィリエイトを経由するURLは、遷移先のURLにする
     */
    public static function innerUrl(?string $url): ?string
    {
        $url = self::extractUrl($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $host = strtolower((string) ($parts['host'] ?? ''));

        foreach (['af.moshimo.com' => 'url', 'hb.afl.rakuten.co.jp' => 'pc'] as $via => $parameter) {
            if ($host === $via && filled($query[$parameter] ?? null) && is_string($query[$parameter])) {
                return self::innerUrl($query[$parameter]);
            }
        }

        return $url;
    }
}
