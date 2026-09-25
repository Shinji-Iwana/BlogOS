<?php

namespace App\Services\WordPress;

use App\Models\Blog;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WordPress REST APIとの通信。
 *
 * 認証情報は現在 .env（services.wp）の全ブログ共通の値を使っている。
 * ブログごとの認証情報への置き換えと app/Clients への移動は段階2で行う
 * （BLOGOS_CURRENT_STATUS.md 3-3、BLOGOS_IMPLEMENTATION_PLAN.md）。
 */
class WordPressApiClient
{
    protected string $rawBase;

    public function __construct(Blog $blog)
    {
        $this->rawBase = rtrim($blog->home, '/');
    }

    public function hasAuth(): bool
    {
        return filled(config('services.wp.username'))
            && filled(config('services.wp.app_password'));
    }

    protected function request(): PendingRequest
    {
        if ($this->hasAuth()) {
            return Http::withBasicAuth(
                config('services.wp.username'),
                config('services.wp.app_password')
            );
        }

        return Http::withOptions([]);
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->send('GET', $endpoint, fn () => $this->request()
            ->timeout(10)
            ->get("{$this->rawBase}{$endpoint}", $query));
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->send('POST', $endpoint, fn () => $this->request()
            ->timeout(10)
            ->post("{$this->rawBase}{$endpoint}", $data));
    }

    public function postMultipart(string $endpoint, string $filePath, string $fieldName = 'file', array $data = []): Response
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException(
                "アップロード対象のファイルが存在しない、または読み込めません。filePath: {$filePath}"
            );
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $fileName = basename($filePath);

        return $this->send('POST', $endpoint, fn () => $this->request()
            ->timeout(10)
            ->attach(
                $fieldName,
                file_get_contents($filePath),
                $fileName,
                [
                    'Content-Type' => $mimeType,
                ]
            )
            ->post(
                "{$this->rawBase}{$endpoint}",
                $data
            ));
    }

    public function delete(string $endpoint, array $query = []): Response
    {
        return $this->send('DELETE', $endpoint, fn () => $this->request()
            ->timeout(10)
            ->delete("{$this->rawBase}{$endpoint}", $query));
    }

    /**
     * 通信を実行し、失敗した場合はログに残す。
     *
     * 呼び出し元のServiceは、失敗を null にして返すため原因が失われる。
     * 調査できるよう、ここでURL・メソッド・ステータスを記録する。
     * 認証情報（Authorizationヘッダー）はログに出さない（DEVELOPMENT_RULES 12-4）。
     */
    protected function send(string $method, string $endpoint, Closure $call): Response
    {
        $url = "{$this->rawBase}{$endpoint}";

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
