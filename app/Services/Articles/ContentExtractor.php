<?php

namespace App\Services\Articles;

use App\Support\HomeUrl;
use DOMDocument;
use DOMElement;

/**
 * 本文（HTML）から、同じブログ内へのリンクと、本文中の画像を取り出す（BLOGOS_DATABASE.md 7章、D-08-04）。
 *
 * DBにもWordPress APIにもアクセスしない。照合（どの記事・メディアか）は呼び出し元で行う。
 */
class ContentExtractor
{
    /**
     * @return array<int, array{url: string, anchor_text: string}>
     */
    public function internalLinks(?string $html, string $home): array
    {
        $links = [];

        foreach ($this->elements($html, 'a') as $element) {
            $href = trim($element->getAttribute('href'));

            if (! $this->isInternalArticleUrl($href, $home)) {
                continue;
            }

            $links[] = [
                'url'         => $href,
                'anchor_text' => trim(preg_replace('/\s+/u', ' ', $element->textContent)),
            ];
        }

        return $links;
    }

    /**
     * 同じサイトの画像だけを返す（アフィリエイトの計測用の画像など、外部の画像は除く）。
     *
     * @return array<int, array{url: string, wordpress_media_id: int|null}>
     */
    public function images(?string $html, string $home): array
    {
        $images = [];

        foreach ($this->elements($html, 'img') as $element) {
            $src = trim($element->getAttribute('src'));

            if ($src === '' || ! $this->isSameSite($src, $home)) {
                continue;
            }

            // ブロックエディタ・クラシックエディタは、画像に wp-image-<メディアID> のクラスを付ける
            $mediaId = preg_match('/\bwp-image-(\d+)\b/', $element->getAttribute('class'), $matches)
                ? (int) $matches[1]
                : null;

            $images[] = ['url' => $src, 'wordpress_media_id' => $mediaId];
        }

        return $images;
    }

    /**
     * 同じブログ内の、記事を指しうるURLか（ページ内リンク・管理画面・アップロードしたファイルなどは除く）
     */
    protected function isInternalArticleUrl(string $href, string $home): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) && ! preg_match('#^https?://#i', $href)) {
            // mailto: tel: javascript: など
            return false;
        }

        // 相対パス（例：../foo）は、記事のURLの形では使われないため対象外とする
        if (! $this->isSameSite($href, $home) || ! (preg_match('#^(https?:)?//#i', $href) || str_starts_with($href, '/'))) {
            return false;
        }

        $path = (string) parse_url($href, PHP_URL_PATH);

        return ! preg_match('#/(wp-admin|wp-content|wp-includes|wp-json|feed)(/|$)#', $path)
            && ! str_ends_with($path, '/wp-login.php');
    }

    /**
     * 同じサイトのURLか。スキームを省略した //host/... と、ホストを含まないパスも扱う
     */
    protected function isSameSite(string $url, string $home): bool
    {
        if (! preg_match('#^(https?:)?//#i', $url)) {
            return true;
        }

        $absolute = str_starts_with($url, '//') ? "https:{$url}" : $url;
        $homeKey = HomeUrl::comparisonKey($home);
        $urlKey = HomeUrl::comparisonKey($absolute);

        return $urlKey === $homeKey || str_starts_with($urlKey, "{$homeKey}/");
    }

    /**
     * @return iterable<DOMElement>
     */
    protected function elements(?string $html, string $tag): iterable
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // 文字コードをUTF-8として読ませる
        $document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return iterator_to_array($document->getElementsByTagName($tag));
    }
}
