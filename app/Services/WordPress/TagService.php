<?php

namespace App\Services\WordPress;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\Blog;
use App\DTO\WordPress\TagApiDto;
use Illuminate\Http\Client\ConnectionException;

class TagService
{
    protected WordPressApiClient $client;

    public function __construct(Blog $blog)
    {
        $this->client = WordPressApiClient::forBlog($blog);
    }

    public function getTags(): ?array
    {
        $tags = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get(
                    '/wp-json/wp/v2/tags',
                    [
                        'per_page' => 100,
                        'page' => $page,
                        'orderby' => 'id',
                        'order' => 'asc',
                    ]
                );
            } catch (ConnectionException $e) {
                return null;
            }
            if (! $response->successful()) {
                return null;
            }

            $pageTags = $response->json();
            if (! is_array($pageTags)) {
                return null;
            }

            foreach ($pageTags as $pageTag) {
                if (! is_array($pageTag)) {
                    continue;
                }

                $tag = TagApiDto::fromApiResponse($pageTag);
                if ($tag === null) {
                    continue;
                }

                $tags[] = $tag;
            }

            $totalPages = (int) $response->header(
                'X-WP-TotalPages',
                $page
            );

            $page++;

        } while ($page <= $totalPages);

        return $tags;
    }

    public function getTag(int $tagId): ?TagApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/tags/{$tagId}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $tagData = $response->json();
        if (! is_array($tagData)) {
            return null;
        }

        return TagApiDto::fromApiResponse($tagData);
    }

    public function createTag(array $data): ?array
    {
        try {
            $response = $this->client->post(
                '/wp-json/wp/v2/tags',
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $createdTag = $response->json();

        return is_array($createdTag)
            ? $createdTag
            : null;
    }

    public function createTags(array $tags): array
    {
        $createdTags = [];

        foreach ($tags as $tag) {
            $createdTag = $this->createTag($tag);
            if ($createdTag === null) {
                return [];
            }

            $createdTags[] = $createdTag;
        }

        return $createdTags;
    }

    public function updateTag(int $tagId, array $data): ?array
    {
        try {
            $response = $this->client->post(
                "/wp-json/wp/v2/tags/{$tagId}",
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $updatedTag = $response->json();

        return is_array($updatedTag)
            ? $updatedTag
            : null;
    }

    public function updateTags(array $tags): array
    {
        $updatedTags = [];

        foreach ($tags as $tag) {
            if (
                ! is_array($tag)
                || ! array_key_exists('id', $tag)
                || ! array_key_exists('data', $tag)
                || ! is_array($tag['data'])
            ) {
                return [];
            }

            $updatedTag = $this->updateTag(
                (int) $tag['id'],
                $tag['data']
            );
            if ($updatedTag === null) {
                return [];
            }

            $updatedTags[] = $updatedTag;
        }

        return $updatedTags;
    }

    public function deleteTag(int $tagId): ?array
    {
        try {
            $response = $this->client->delete(
                "/wp-json/wp/v2/tags/{$tagId}",
                [
                    'force' => true,
                ]
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $deletedTag = $response->json();

        return is_array($deletedTag)
            ? $deletedTag
            : null;
    }

    public function deleteTags(array $tagIds): array
    {
        $deletedTags = [];

        foreach ($tagIds as $tagId) {
            $deletedTag = $this->deleteTag(
                (int) $tagId
            );
            if ($deletedTag === null) {
                return [];
            }

            $deletedTags[] = $deletedTag;
        }

        return $deletedTags;
    }
}
