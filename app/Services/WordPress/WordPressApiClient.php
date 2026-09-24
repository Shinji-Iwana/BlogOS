<?php

namespace App\Services\WordPress;

use App\Models\Blog;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

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
        return $this->request()
            ->timeout(10)
            ->get("{$this->rawBase}{$endpoint}", $query);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->request()
            ->timeout(10)
            ->post("{$this->rawBase}{$endpoint}", $data);
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

        return $this->request()
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
            );
    }

    public function delete(string $endpoint, array $query = []): Response
    {
        return $this->request()
            ->timeout(10)
            ->delete("{$this->rawBase}{$endpoint}", $query);
    }
}
