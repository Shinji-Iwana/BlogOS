<?php

namespace App\Enums;

/**
 * インデックスの登録状態の分類（google_index_statuses.category）。D-37。
 *
 * Search Console の URL 検査 API の coverageState（英語）から決める。
 */
enum GoogleIndexCategory: string
{
    case Indexed = 'indexed';
    // Google が読んだうえで、登録を見送った（本文の質・独自性・内部リンクを含めた改修が必要）
    case Crawled = 'crawled';
    // Google は URL を知っているが、まだ読みに来ていない（内部リンク・サイトマップで見つけてもらう）
    case Discovered = 'discovered';
    // Google が URL を知らない
    case Unknown = 'unknown';
    // 別のページが正規のページとされている
    case Duplicate = 'duplicate';
    // noindex・robots.txt・リダイレクト・404 などで除外
    case Excluded = 'excluded';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Indexed    => '登録済み',
            self::Crawled    => 'クロール済み - インデックス未登録',
            self::Discovered => '検出 - インデックス未登録',
            self::Unknown    => 'Google が知らない URL',
            self::Duplicate  => '重複（別のページが正規）',
            self::Excluded   => '除外（noindex・robots.txt・リダイレクト・404 など）',
            self::Other      => 'その他',
        };
    }

    /**
     * 改修の方針（一覧の表示と、改修の指示文に使う）
     */
    public function advice(): string
    {
        return match ($this) {
            self::Indexed    => '',
            self::Crawled    => 'Google が読んだうえで登録を見送った状態。本文の質・独自性（ほかの記事・ほかのサイトにない内容）・内部リンクを含めて改修する。',
            self::Discovered => 'Google がまだ読みに来ていない状態。ほかの記事（ロードマップ・関連記事）からの内部リンクを増やし、見つけてもらいやすくする。',
            self::Unknown    => 'Google が URL を知らない状態。ロードマップ・関連記事からの内部リンクと、サイトマップへの掲載を確認する。',
            self::Duplicate  => '別のページが正規のページとされている。内容の重なる記事との違いを明確にするか、統合を検討する。',
            self::Excluded   => 'noindex・robots.txt・リダイレクト・404 などの設定を確認する。',
            self::Other      => 'Search Console で詳細を確認する。',
        };
    }

    public static function fromCoverage(?string $coverageState, ?string $verdict): self
    {
        $state = strtolower((string) $coverageState);

        return match (true) {
            // 「Submitted and indexed」「Indexed, not submitted in sitemap」「Indexed, though blocked by robots.txt」
            str_starts_with($state, 'submitted and indexed') || str_starts_with($state, 'indexed') => self::Indexed,
            str_contains($state, 'crawled - currently not indexed')                            => self::Crawled,
            str_contains($state, 'discovered - currently not indexed')                         => self::Discovered,
            str_contains($state, 'unknown to google')                                          => self::Unknown,
            str_contains($state, 'duplicate') || str_contains($state, 'alternate page')        => self::Duplicate,
            str_contains($state, 'noindex') || str_contains($state, 'robots.txt') || str_contains($state, 'redirect')
                || str_contains($state, 'not found') || str_contains($state, '404') || str_contains($state, 'blocked') => self::Excluded,
            str_contains($state, 'indexed') || strtoupper((string) $verdict) === 'PASS'        => self::Indexed,
            default                                                                            => self::Other,
        };
    }
}
