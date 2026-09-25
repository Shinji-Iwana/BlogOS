<?php

namespace App\Clients\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google API（GA4 Data・GA4 Admin・Search Console・AdSense）の REST 呼び出し（D-21-06）。
 *
 * 有効なアクセストークンを受け取って呼ぶだけで、トークンの更新は App\Services\Google\GoogleTokenService が行う。
 * 失敗（通信エラー・HTTPエラー・JSONでない応答）は GoogleApiException にする。
 */
class GoogleApiClient
{
    public const ANALYTICS_DATA = 'https://analyticsdata.googleapis.com/v1beta';

    public const ANALYTICS_ADMIN = 'https://analyticsadmin.googleapis.com/v1beta';

    public const SEARCH_CONSOLE = 'https://www.googleapis.com/webmasters/v3';

    public const ADSENSE = 'https://adsense.googleapis.com/v2';

    protected const TIMEOUT_SECONDS = 60;

    public function __construct(
        protected string $accessToken
    ) {
    }

    /**
     * @param array<string, mixed>|string $query 配列、または組み立て済みのクエリ文字列（同じ名前を繰り返す場合）
     *
     * @throws GoogleApiException
     */
    public function get(string $url, array|string $query = []): array
    {
        $fullUrl = is_string($query) && $query !== '' ? "{$url}?{$query}" : $url;

        // 空の配列を渡すと、URLに含めたクエリ文字列が消えるため、文字列の場合は渡さない
        return $this->send('GET', $fullUrl, fn () => $this->request()->get($fullUrl, is_array($query) && $query !== [] ? $query : null));
    }

    /**
     * @throws GoogleApiException
     */
    public function post(string $url, array $body): array
    {
        return $this->send('POST', $url, fn () => $this->request()->post($url, $body));
    }

    protected function request()
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->withToken($this->accessToken)->acceptJson();
    }

    /**
     * @throws GoogleApiException
     */
    protected function send(string $method, string $url, callable $call): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException $e) {
            Log::warning('Google APIへの接続に失敗しました。', ['method' => $method, 'url' => $url, 'message' => $e->getMessage()]);

            throw new GoogleApiException("Googleに接続できませんでした：{$e->getMessage()}", $method, $url);
        }

        if ($response->failed()) {
            Log::warning('Google APIがエラーを返しました。', ['method' => $method, 'url' => $url, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 2000)]);

            $message = $response->json('error.message') ?? "HTTP {$response->status()}";
            throw new GoogleApiException("Google APIがエラーを返しました：{$message}", $method, $url, $response->status(), $response->body());
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new GoogleApiException('Google APIの応答がJSONではありません。', $method, $url, $response->status(), $response->body());
        }

        return $data;
    }
}
