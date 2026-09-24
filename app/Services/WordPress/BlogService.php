<?php

namespace App\Services\WordPress;

use App\Models\Blog;
use Illuminate\Http\Client\ConnectionException;

class BlogService
{
    protected WordPressApiClient $client;

    public function __construct(Blog $blog)
    {
        $this->client = new WordPressApiClient($blog);
    }

    public function getSite(): ?array
    {
        try {
            $response = $this->client->get('/wp-json');
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data)
            ? $data
            : null;
    }
}
