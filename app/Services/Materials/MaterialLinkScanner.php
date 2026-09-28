<?php

namespace App\Services\Materials;

use App\Enums\MaterialKind;
use App\Support\AffiliateLink;

/**
 * 記事の本文から、教材のリンク（アフィリエイト）を読み取る（D-30）。
 *
 * 対象は、アフィリエイトのサービスを経由するリンクだけ（もしも・楽天アフィリエイト・Udemyの紹介リンク・Amazonの紹介リンク）。
 * 別のサイトへの普通のリンクは、教材として扱わない。
 */
class MaterialLinkScanner
{
    /**
     * 紹介リンクの文字だけで、教材の名前にならない文言
     */
    protected const GENERIC_TEXTS = ['/で見る$/u', '/^詳しく/u', '/^公式サイト/u', '/^購入/u', '/^こちら/u'];

    /**
     * @return list<array{key: string, url: string, kind: MaterialKind, name: string|null, link_type: string, group: int}>
     *         link_type は amazon / rakuten / affiliate。group は、同じ教材とみなすリンクのまとまり（同じ見出しの下にあるリンク）
     */
    public function scan(string $content): array
    {
        if (! preg_match_all('/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $links = [];
        $groups = [];
        foreach ($matches as $match) {
            $url = AffiliateLink::extractUrl(html_entity_decode($match[2][0], ENT_QUOTES | ENT_HTML5));
            if ($url === null || ! $this->isAffiliate($url)) {
                continue;
            }
            $key = AffiliateLink::key($url);
            if ($key === null) {
                continue;
            }

            $heading = $this->headingBefore($content, $match[0][1]);
            $text = $this->cleanText($match[3][0]);
            $kind = match (true) {
                str_starts_with($key, 'amazon:'), str_starts_with($key, 'rakuten-books:') => MaterialKind::Book,
                str_starts_with($key, 'udemy-')                                         => MaterialKind::Udemy,
                default                                                                 => MaterialKind::School,
            };
            $specific = $text !== null && ! $this->isGeneric($text);
            $name = $specific ? $text : $heading;

            // 書籍は、同じ見出しの下にある「Amazonで見る」「楽天で見る」のリンクを、同じ教材とみなす
            // （リンクの文字が書名のときは、書名ごとに分ける。書籍の一覧のページなど）
            $groupKey = $kind === MaterialKind::Book && $name !== null ? 'book:' . ($specific ? "name:{$name}" : $heading) : "link:{$key}";
            $groups[$groupKey] ??= count($groups);

            $links[] = [
                'key'       => $key,
                'url'       => $url,
                'kind'      => $kind,
                'name'      => $name,
                'link_type' => match (true) {
                    str_starts_with($key, 'amazon:')        => 'amazon',
                    str_starts_with($key, 'rakuten-books:') => 'rakuten',
                    default                                 => 'affiliate',
                },
                'group'     => $groups[$groupKey],
            ];
        }

        return $links;
    }

    /**
     * @return list<string> 本文にある教材のリンクの識別子
     */
    public function keys(string $content): array
    {
        return array_values(array_unique(array_column($this->scan($content), 'key')));
    }

    public function isAffiliate(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return in_array($host, ['af.moshimo.com', 'hb.afl.rakuten.co.jp', 'trk.udemy.com', 'amzn.to'], true)
            || (preg_match('/(^|\.)amazon\.co\.jp$/', $host) && preg_match('/(^|&)tag=/', $query));
    }

    /**
     * リンクより前にある、いちばん近い見出し（h2〜h4）の文字
     */
    protected function headingBefore(string $content, int $offset): ?string
    {
        $before = substr($content, 0, $offset);
        if (! preg_match_all('/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $before, $matches)) {
            return null;
        }

        return $this->cleanText((string) end($matches[1]));
    }

    protected function cleanText(string $html): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
        // 「→ 講座名（Udemy）」「〇〇はこちら」の飾りを外す
        $text = preg_replace(['/^[→＞>▶︎▶\s]+/u', '/[（(]\s*Udemy\s*[）)]$/iu', '/[（(]?\s*(無料相談|詳細)?はこちら\s*[）)]?$/u'], '', $text);
        $text = trim((string) $text);

        return $text !== '' ? mb_substr($text, 0, 255) : null;
    }

    protected function isGeneric(string $text): bool
    {
        foreach (self::GENERIC_TEXTS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
