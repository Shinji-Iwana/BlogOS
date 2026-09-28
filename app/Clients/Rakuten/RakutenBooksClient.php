<?php

namespace App\Clients\Rakuten;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 楽天ブックス書籍検索API（楽天ウェブサービス）。教材の書籍の情報を、決まった形で取得する（D-30）。
 *
 * 書名・著者・出版社・発売日・ISBN・紹介文を取得できる。アプリIDはログ・例外に含めない。
 */
class RakutenBooksClient
{
    /**
     * PC・システム開発のジャンル（候補探しで、関係のない本を減らすため）
     */
    public const GENRE_COMPUTER = '001005';

    public function __construct(
        protected ?string $applicationId,
        protected string $url,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(config('services.rakuten.application_id'), (string) config('services.rakuten.books_url'));
    }

    public function isConfigured(): bool
    {
        return filled($this->applicationId);
    }

    /**
     * @return list<array{title: string, author: string, publisher: string, sales_date: string, isbn: string, caption: string, url: string}>
     */
    public function byIsbn(string $isbn): array
    {
        return $this->search(['isbn' => $isbn]);
    }

    /**
     * 書名（の一部）と著者で探す。新しい順
     *
     * @return list<array{title: string, author: string, publisher: string, sales_date: string, isbn: string, caption: string, url: string}>
     */
    public function byTitleAndAuthor(?string $title, ?string $author, int $hits = 10): array
    {
        return $this->search(array_filter([
            'title'  => $title,
            'author' => $author,
            'sort'   => '-releaseDate',
            'hits'   => $hits,
        ], fn ($value) => filled($value)));
    }

    /**
     * 書名に語句を含む、PC・システム開発の本。売れている順
     *
     * @return list<array{title: string, author: string, publisher: string, sales_date: string, isbn: string, caption: string, url: string}>
     */
    public function computerBooks(string $title, int $hits = 20): array
    {
        return $this->search(['title' => $title, 'booksGenreId' => self::GENRE_COMPUTER, 'sort' => 'sales', 'hits' => $hits]);
    }

    /**
     * @return list<array{title: string, author: string, publisher: string, sales_date: string, isbn: string, caption: string, url: string}>
     *
     * @throws RakutenException
     */
    protected function search(array $parameters): array
    {
        if (! $this->isConfigured()) {
            throw new RakutenException('楽天ウェブサービスのアプリIDが設定されていません（.env の RAKUTEN_APPLICATION_ID）。');
        }

        try {
            $response = Http::timeout(20)->acceptJson()->get($this->url, $parameters + [
                'applicationId'  => $this->applicationId,
                'format'         => 'json',
                'formatVersion'  => 2,
                // 在庫がない本も含める（出版日・新しい版の確認のため）
                'outOfStockFlag' => 1,
            ]);
        } catch (ConnectionException $e) {
            // 接続エラーの文言にはURL（アプリIDを含む）が入るため、アプリIDを伏せる
            $message = str_replace((string) $this->applicationId, '***', $e->getMessage());

            throw new RakutenException("楽天ブックスAPIに接続できませんでした：{$message}");
        }

        if ($response->status() === 404) {
            // 該当する本がない
            return [];
        }
        if ($response->failed()) {
            $message = (string) ($response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}");
            Log::warning('楽天ブックスAPIがエラーを返しました。', ['status' => $response->status(), 'message' => $message]);

            throw new RakutenException("楽天ブックスAPIがエラーを返しました：{$message}");
        }

        $items = (array) ($response->json('Items') ?? []);

        return array_values(array_map(fn ($item) => [
            'title'      => trim(($item['title'] ?? '') . (filled($item['subTitle'] ?? null) ? " {$item['subTitle']}" : '')),
            'author'     => (string) ($item['author'] ?? ''),
            'publisher'  => (string) ($item['publisherName'] ?? ''),
            'sales_date' => (string) ($item['salesDate'] ?? ''),
            'isbn'       => (string) ($item['isbn'] ?? ''),
            'caption'    => mb_substr((string) ($item['itemCaption'] ?? ''), 0, 600),
            'url'        => (string) ($item['itemUrl'] ?? ''),
        ], array_map(fn ($item) => (array) ($item['Item'] ?? $item), $items)));
    }
}
