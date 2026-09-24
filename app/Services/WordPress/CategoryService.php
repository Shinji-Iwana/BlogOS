<?php

namespace App\Services\WordPress;

use App\Repositories\BlogRepository;
use App\DTO\WordPress\CategoryApiDto;
use Illuminate\Http\Client\ConnectionException;

class CategoryService
{
    protected WordPressApiClient $client;

    public function __construct(int $blogId, BlogRepository $blogRepository)
    {
        $this->client = new WordPressApiClient(
            $blogId,
            $blogRepository
        );
    }

    public function getCategories(): ?array
    {
        $categories = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get(
                    '/wp-json/wp/v2/categories',
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

            $pageCategories = $response->json();
            if (! is_array($pageCategories)) {
                return null;
            }

            foreach ($pageCategories as $pageCategory) {
                if (! is_array($pageCategory)) {
                    continue;
                }

                $category = CategoryApiDto::fromApiResponse($pageCategory);
                if ($category === null) {
                    continue;
                }

                $categories[] = $category;
            }

            $totalPages = (int) $response->header(
                'X-WP-TotalPages',
                $page
            );

            $page++;

        } while ($page <= $totalPages);

        return $categories;
    }

    public function getCategory(int $categoryId): ?CategoryApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/categories/{$categoryId}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $categoryData = $response->json();
        if (! is_array($categoryData)) {
            return null;
        }

        return CategoryApiDto::fromApiResponse($categoryData);
    }

    public function createCategory(array $data): ?array
    {
        try {
            $response = $this->client->post(
                '/wp-json/wp/v2/categories',
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $createdCategory = $response->json();

        return is_array($createdCategory)
            ? $createdCategory
            : null;
    }

    public function createCategories(array $categories): array
    {
        $createdCategories = [];

        foreach ($categories as $category) {
            $createdCategory = $this->createCategory($category);
            if ($createdCategory === null) {
                return [];
            }

            $createdCategories[] = $createdCategory;
        }

        return $createdCategories;
    }

    public function updateCategory(int $categoryId, array $data): ?array
    {
        try {
            $response = $this->client->post(
                "/wp-json/wp/v2/categories/{$categoryId}",
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $updatedCategory = $response->json();

        return is_array($updatedCategory)
            ? $updatedCategory
            : null;
    }

    public function updateCategories(array $categories): array
    {
        $updatedCategories = [];

        foreach ($categories as $category) {
            if (
                ! is_array($category)
                || ! array_key_exists('id', $category)
                || ! array_key_exists('data', $category)
                || ! is_array($category['data'])
            ) {
                return [];
            }

            $updatedCategory = $this->updateCategory(
                (int) $category['id'],
                $category['data']
            );
            if ($updatedCategory === null) {
                return [];
            }

            $updatedCategories[] = $updatedCategory;
        }

        return $updatedCategories;
    }

    public function deleteCategory(int $categoryId): ?array
    {
        try {
            $response = $this->client->delete(
                "/wp-json/wp/v2/categories/{$categoryId}",
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

        $deletedCategory = $response->json();

        return is_array($deletedCategory)
            ? $deletedCategory
            : null;
    }

    public function deleteCategories(array $categoryIds): array
    {
        $deletedCategories = [];

        foreach ($categoryIds as $categoryId) {
            $deletedCategory = $this->deleteCategory(
                (int) $categoryId
            );
            if ($deletedCategory === null) {
                return [];
            }

            $deletedCategories[] = $deletedCategory;
        }

        return $deletedCategories;
    }
}
