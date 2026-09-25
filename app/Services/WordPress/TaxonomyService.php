<?php

namespace App\Services\WordPress;

use App\Models\Blog;
use App\DTO\WordPress\TaxonomyApiDto;
use Illuminate\Http\Client\ConnectionException;

class TaxonomyService
{
    protected WordPressApiClient $client;

    public function __construct(Blog $blog)
    {
        $this->client = new WordPressApiClient($blog);
    }

    public function getTaxonomies(): ?array
    {
        try {
            $response = $this->client->get(
                '/wp-json/wp/v2/taxonomies'
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $taxonomiesData = $response->json();
        if (! is_array($taxonomiesData)) {
            return null;
        }

        $taxonomies = [];

        foreach ($taxonomiesData as $taxonomyData) {
            if (! is_array($taxonomyData)) {
                continue;
            }

            $taxonomy = TaxonomyApiDto::fromApiResponse($taxonomyData);
            if ($taxonomy === null) {
                continue;
            }

            $taxonomies[] = $taxonomy;
        }

        return $taxonomies;
    }

    public function getTaxonomy(string $taxonomy): ?TaxonomyApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/taxonomies/{$taxonomy}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $taxonomyData = $response->json();
        if (! is_array($taxonomyData)) {
            return null;
        }

        return TaxonomyApiDto::fromApiResponse($taxonomyData);
    }
}
