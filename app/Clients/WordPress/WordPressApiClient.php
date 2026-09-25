<?php

namespace App\Clients\WordPress;

use App\Models\Blog;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WordPress REST APIとの通信（BLOGOS_ARCHITECTURE.md 12章）。
 *
 * 通信だけを担当し、DBへの保存や業務判断はしない。
 * 認証情報は、ブログごとに blog_credentials に暗号化して保存したものを使う（D-03-02）。
 * 認証情報がないブログは、認証なしで通信する（公開されている情報だけ取得できる）。
 */
class WordPressApiClient
{
    protected const TIMEOUT_SECONDS = 10;

    protected string $home;

    /**
     * @param string      $home     ブログのホームURL（WordPressのサイトアドレス。D-13-01）
     * @param string|null $username WordPressのユーザー名
     * @param string|null $password Application Password
     */
    public function __construct(
        string $home,
        protected ?string $username = null,
        protected ?string $password = null
    ) {
        $this->home = rtrim($home, '/');
    }

    /**
     * 登録済みのブログの接続先と認証情報で作る。
     */
    public static function forBlog(Blog $blog): self
    {
        // 複数のブログをまとめて取得した場合でも遅延読み込みにならないよう、明示的に読み込む
        $credential = $blog->loadMissing('credential')->credential;

        return new self(
            $blog->home,
            $credential?->username,
            $credential?->secret
        );
    }

    public function hasAuth(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    protected function request(): PendingRequest
    {
        $request = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson();

        if ($this->hasAuth()) {
            $request = $request->withBasicAuth($this->username, $this->password);
        }

        return $request;
    }

    /**
     * @param string $endpoint ホームURLからのパス（例：/wp-json/wp/v2/posts）
     */
    public function get(string $endpoint, array $query = []): Response
    {
        $url = "{$this->home}{$endpoint}";

        return $this->send('GET', $url, fn () => $this->request()->get($url, $query));
    }

    public function post(string $endpoint, array $data = []): Response
    {
        $url = "{$this->home}{$endpoint}";

        return $this->send('POST', $url, fn () => $this->request()->post($url, $data));
    }

    public function postMultipart(string $endpoint, string $filePath, string $fieldName = 'file', array $data = []): Response
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException(
                "アップロード対象のファイルが存在しない、または読み込めません。filePath: {$filePath}"
            );
        }

        $url = "{$this->home}{$endpoint}";
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return $this->send('POST', $url, fn () => $this->request()
            ->attach($fieldName, file_get_contents($filePath), basename($filePath), ['Content-Type' => $mimeType])
            ->post($url, $data));
    }

    public function delete(string $endpoint, array $query = []): Response
    {
        $url = "{$this->home}{$endpoint}";

        return $this->send('DELETE', $url, fn () => $this->request()->delete($url, $query));
    }

    /**
     * 一覧をすべてのページについて取得する（BLOGOS_WORDPRESS_API.md 9章）。
     *
     * 途中のページで失敗した場合は例外を投げ、途中までの結果は返さない。
     * 削除の判定は「一覧を最後まで取得できた場合」だけ行うため（D-09-03）。
     *
     * @return array<int, array> 全ページの項目
     *
     * @throws WordPressApiException
     */
    public function getAllPages(string $endpoint, array $query = []): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->getOrFail($endpoint, array_merge($query, [
                'per_page' => 100,
                'page'     => $page,
            ]));

            $data = $response->json();

            if (! is_array($data) || ! array_is_list($data)) {
                throw new WordPressApiException(
                    'WordPress APIの応答が一覧の形式ではありません。',
                    'GET',
                    "{$this->home}{$endpoint}",
                    $response->status(),
                    $response->body()
                );
            }

            array_push($items, ...$data);

            $totalPages = (int) $response->header('X-WP-TotalPages');
            $page++;
        } while ($page <= $totalPages);

        return $items;
    }

    /**
     * 取得し、成功しなかった場合（通信エラー・HTTPエラー・JSONでない応答）は例外を投げる。
     *
     * @throws WordPressApiException
     */
    public function getOrFail(string $endpoint, array $query = []): Response
    {
        $url = "{$this->home}{$endpoint}";

        try {
            $response = $this->get($endpoint, $query);
        } catch (ConnectionException $e) {
            throw new WordPressApiException("WordPressに接続できませんでした：{$e->getMessage()}", 'GET', $url);
        }

        if ($response->failed()) {
            throw new WordPressApiException(
                "WordPress APIがエラーを返しました（HTTP {$response->status()}）。",
                'GET',
                $url,
                $response->status(),
                $response->body()
            );
        }

        if (! is_array($response->json())) {
            throw new WordPressApiException(
                'WordPress APIの応答がJSONではありません。',
                'GET',
                $url,
                $response->status(),
                $response->body()
            );
        }

        return $response;
    }

    /**
     * ホームURLが分かる前（ブログ登録時のAPI Discovery）に、任意のURLを取得する。
     * 認証情報は付けない。
     */
    public static function fetchUrl(string $url): Response
    {
        $client = new self($url);

        return $client->send('GET', $url, fn () => Http::timeout(self::TIMEOUT_SECONDS)->get($url));
    }

    /**
     * 通信を実行し、失敗した場合はログに残す。
     *
     * 認証情報（Authorizationヘッダー）はログに出さない（DEVELOPMENT_RULES 12-4）。
     */
    protected function send(string $method, string $url, Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::warning('WordPress APIへの接続に失敗しました。', [
                'method'  => $method,
                'url'     => $url,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($response->failed()) {
            Log::warning('WordPress APIがエラーを返しました。', [
                'method' => $method,
                'url'    => $url,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 2000),
            ]);
        }

        return $response;
    }
}
