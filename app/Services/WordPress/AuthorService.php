<?php

namespace App\Services\WordPress;

use App\Repositories\BlogRepository;
use App\DTO\WordPress\AuthorApiDto;
use Illuminate\Http\Client\ConnectionException;

class AuthorService
{
    protected WordPressApiClient $client;

    public function __construct(int $blogId, BlogRepository $blogRepository)
    {
        $this->client = new WordPressApiClient(
            $blogId,
            $blogRepository
        );
    }

    public function getAuthor(int $authorId): ?AuthorApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/users/{$authorId}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $authorData = $response->json();
        if (! is_array($authorData)) {
            return null;
        }

        return AuthorApiDto::fromApiResponse([
            $authorData,
        ])?->toArray() !== null
            ? new AuthorApiDto($authorData)
            : null;
    }

    public function getAuthors(): ?array
    {
        $authors = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get(
                    '/wp-json/wp/v2/users',
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

            $pageAuthors = $response->json();
            if (! is_array($pageAuthors)) {
                return null;
            }

            foreach ($pageAuthors as $pageAuthor) {
                if (! is_array($pageAuthor)) {
                    continue;
                }

                $author = AuthorApiDto::fromApiResponse([
                    $pageAuthor,
                ]);
                if ($author === null) {
                    continue;
                }

                $authors[] = $author;
            }

            $totalPages = (int) $response->header(
                'X-WP-TotalPages',
                $page
            );

            $page++;

        } while ($page <= $totalPages);

        return $authors;
    }

    public function createAuthor(array $data): ?array
    {
        try {
            $response = $this->client->post(
                '/wp-json/wp/v2/users',
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $createdAuthor = $response->json();

        return is_array($createdAuthor)
            ? $createdAuthor
            : null;
    }

    public function createAuthors(array $authors): array
    {
        $createdAuthors = [];

        foreach ($authors as $author) {
            $createdAuthor = $this->createAuthor($author);
            if ($createdAuthor === null) {
                return [];
            }

            $createdAuthors[] = $createdAuthor;
        }

        return $createdAuthors;
    }

    public function updateAuthor(int $authorId, array $data): ?array
    {
        try {
            $response = $this->client->post(
                "/wp-json/wp/v2/users/{$authorId}",
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $updatedAuthor = $response->json();

        return is_array($updatedAuthor)
            ? $updatedAuthor
            : null;
    }

    public function updateAuthors(array $authors): array
    {
        $updatedAuthors = [];

        foreach ($authors as $author) {
            if (
                ! is_array($author)
                || ! array_key_exists('id', $author)
                || ! array_key_exists('data', $author)
                || ! is_array($author['data'])
            ) {
                return [];
            }

            $updatedAuthor = $this->updateAuthor(
                (int) $author['id'],
                $author['data']
            );
            if ($updatedAuthor === null) {
                return [];
            }

            $updatedAuthors[] = $updatedAuthor;
        }

        return $updatedAuthors;
    }

    public function deleteAuthor(int $authorId, ?int $reassign = null): ?array
    {
        try {
            $params = [
                'force' => true,
            ];

            if ($reassign !== null) {
                $params['reassign'] = $reassign;
            }

            $response = $this->client->delete(
                "/wp-json/wp/v2/users/{$authorId}",
                $params
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $deletedAuthor = $response->json();

        return is_array($deletedAuthor)
            ? $deletedAuthor
            : null;
    }

    public function deleteAuthors(array $authorIds, ?int $reassign = null): array
    {
        $deletedAuthors = [];

        foreach ($authorIds as $authorId) {
            $deletedAuthor = $this->deleteAuthor(
                (int) $authorId,
                $reassign
            );
            if ($deletedAuthor === null) {
                return [];
            }

            $deletedAuthors[] = $deletedAuthor;
        }

        return $deletedAuthors;
    }
}
