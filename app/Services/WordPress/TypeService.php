<?php

namespace App\Services\WordPress;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\Blog;
use App\DTO\WordPress\TypeApiDto;
use Illuminate\Http\Client\ConnectionException;

class TypeService
{
    protected WordPressApiClient $client;

    public function __construct(Blog $blog)
    {
        $this->client = WordPressApiClient::forBlog($blog);
    }

    public function getTypes(): ?TypeApiDto
    {
        try {
            $response = $this->client->get(
                '/wp-json/wp/v2/types'
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $typesData = $response->json();
        if (! is_array($typesData)) {
            return null;
        }

        return TypeApiDto::fromApiResponse($typesData);
    }

    public function getType(string $type): ?TypeApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/types/{$type}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $typeData = $response->json();
        if (! is_array($typeData)) {
            return null;
        }

        $typeApiDto = TypeApiDto::fromApiResponse([
            $type => $typeData,
        ]);
        if ($typeApiDto->toArray() === []) {
            return null;
        }

        return $typeApiDto;
    }
}
