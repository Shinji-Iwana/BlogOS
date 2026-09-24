<?php

namespace App\Services\WordPress;

use App\Repositories\BlogRepository;
use App\DTO\WordPress\PageApiDto;
use Illuminate\Http\Client\ConnectionException;

class PageService
{
    protected WordPressApiClient $client;

    public function __construct(int $blogId, BlogRepository $blogRepository)
    {
        $this->client = new WordPressApiClient(
            $blogId,
            $blogRepository
        );
    }

    public function getPages(): ?array
    {
        $pages = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get(
                    '/wp-json/wp/v2/pages',
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

            $pagePages = $response->json();
            if (! is_array($pagePages)) {
                return null;
            }

            foreach ($pagePages as $pagePage) {
                if (! is_array($pagePage)) {
                    continue;
                }

                $pageApiDto = PageApiDto::fromApiResponse($pagePage);
                if ($pageApiDto === null) {
                    continue;
                }

                $pages[] = $pageApiDto;
            }

            $totalPages = (int) $response->header(
                'X-WP-TotalPages',
                $page
            );

            $page++;

        } while ($page <= $totalPages);

        return $pages;
    }

    public function getPage(int $pageId): ?PageApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/pages/{$pageId}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $pageData = $response->json();
        if (! is_array($pageData)) {
            return null;
        }

        return PageApiDto::fromApiResponse($pageData);
    }

    public function createPage(array $data): ?array
    {
        try {
            $response = $this->client->post(
                '/wp-json/wp/v2/pages',
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $createdPage = $response->json();

        return is_array($createdPage)
            ? $createdPage
            : null;
    }

    public function createPages(array $pages): array
    {
        $createdPages = [];

        foreach ($pages as $page) {
            $createdPage = $this->createPage($page);
            if ($createdPage === null) {
                return [];
            }

            $createdPages[] = $createdPage;
        }

        return $createdPages;
    }

    public function updatePage(int $pageId, array $data): ?array
    {
        try {
            $response = $this->client->post(
                "/wp-json/wp/v2/pages/{$pageId}",
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $updatedPage = $response->json();

        return is_array($updatedPage)
            ? $updatedPage
            : null;
    }

    public function updatePages(array $pages): array
    {
        $updatedPages = [];

        foreach ($pages as $page) {
            if (
                ! is_array($page)
                || ! array_key_exists('id', $page)
                || ! array_key_exists('data', $page)
                || ! is_array($page['data'])
            ) {
                return [];
            }

            $updatedPage = $this->updatePage(
                (int) $page['id'],
                $page['data']
            );
            if ($updatedPage === null) {
                return [];
            }

            $updatedPages[] = $updatedPage;
        }

        return $updatedPages;
    }

    public function deletePage(int $pageId): ?array
    {
        try {
            $response = $this->client->delete(
                "/wp-json/wp/v2/pages/{$pageId}",
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

        $deletedPage = $response->json();

        return is_array($deletedPage)
            ? $deletedPage
            : null;
    }

    public function deletePages(array $pageIds): array
    {
        $deletedPages = [];

        foreach ($pageIds as $pageId) {
            $deletedPage = $this->deletePage(
                (int) $pageId
            );
            if ($deletedPage === null) {
                return [];
            }

            $deletedPages[] = $deletedPage;
        }

        return $deletedPages;
    }
}
