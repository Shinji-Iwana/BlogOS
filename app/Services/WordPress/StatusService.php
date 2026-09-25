<?php

namespace App\Services\WordPress;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\Blog;
use App\DTO\WordPress\StatusApiDto;
use Illuminate\Http\Client\ConnectionException;

class StatusService
{
    protected WordPressApiClient $client;

    public function __construct(Blog $blog)
    {
        $this->client = WordPressApiClient::forBlog($blog);
    }

    public function getStatuses(): ?array
    {
        try {
            $response = $this->client->get(
                '/wp-json/wp/v2/statuses'
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $statusesData = $response->json();
        if (! is_array($statusesData)) {
            return null;
        }

        $statuses = [];

        foreach ($statusesData as $statusData) {
            if (! is_array($statusData)) {
                continue;
            }

            $status = StatusApiDto::fromApiResponse($statusData);
            if ($status === null) {
                continue;
            }

            $statuses[] = $status;
        }

        return $statuses;
    }

    public function getStatus(string $status): ?StatusApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/statuses/{$status}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $statusData = $response->json();
        if (! is_array($statusData)) {
            return null;
        }

        return StatusApiDto::fromApiResponse($statusData);
    }
}
