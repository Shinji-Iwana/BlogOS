<?php

namespace App\Clients\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PageSpeed Insights API（v5。D-78）。1つの URL を、携帯（mobile）かデスクトップ（desktop）で測り、応答（Lighthouse の結果）をそのまま返す。
 *
 * 4つの区分（パフォーマンス・ユーザー補助・おすすめの方法・SEO）を1回で測り、文言は日本語で受け取る。
 * APIキーはログ・例外に含めない（接続の失敗の文言に URL が入るため、伏せる）。
 */
class PageSpeedClient
{
    public const STRATEGIES = ['mobile', 'desktop'];

    public const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];

    public function __construct(
        protected ?string $key,
        protected string $url,
        protected int $timeout = 90,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(config('services.pagespeed.key'), (string) config('services.pagespeed.url'), (int) config('blogos.pagespeed.timeout', 90));
    }

    public function isConfigured(): bool
    {
        return filled($this->key);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PageSpeedException
     */
    public function run(string $pageUrl, string $strategy): array
    {
        if (! $this->isConfigured()) {
            throw new PageSpeedException('PageSpeed Insights の APIキーが設定されていません（.env の GOOGLE_PAGESPEED_API_KEY）。');
        }

        // category は同じ名前で4つ送る（http_build_query の配列の形 category[0]= にならないよう、文字列で組み立てる）
        $query = http_build_query(['url' => $pageUrl, 'strategy' => $strategy, 'locale' => 'ja', 'key' => $this->key])
            . '&' . implode('&', array_map(fn ($category) => 'category=' . rawurlencode(str_replace('-', '_', strtoupper($category))), self::CATEGORIES));

        try {
            $response = Http::timeout($this->timeout)->acceptJson()->get($this->url . '?' . $query);
        } catch (ConnectionException $e) {
            throw new PageSpeedException('PageSpeed Insights API に接続できませんでした：' . str_replace((string) $this->key, '***', $e->getMessage()));
        }

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? "HTTP {$response->status()}");
            Log::warning('PageSpeed Insights API がエラーを返しました。', ['status' => $response->status(), 'message' => $message, 'url' => $pageUrl, 'strategy' => $strategy]);

            throw new PageSpeedException("PageSpeed Insights API がエラーを返しました：{$message}", $response->status());
        }

        return (array) $response->json();
    }
}
