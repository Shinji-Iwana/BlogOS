<?php

namespace App\Services\WordPress;

use App\Models\Blog;
use App\DTO\WordPress\PostApiDto;
use Illuminate\Http\Client\ConnectionException;

class PostService
{
    protected WordPressApiClient $client;

    public function __construct(Blog $blog)
    {
        $this->client = new WordPressApiClient($blog);
    }

    public function getPosts(): ?array
    {
        $posts = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get(
                    '/wp-json/wp/v2/posts',
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

            $pagePosts = $response->json();
            if (! is_array($pagePosts)) {
                return null;
            }

            foreach ($pagePosts as $pagePost) {
                if (! is_array($pagePost)) {
                    continue;
                }

                $post = PostApiDto::fromApiResponse($pagePost);
                if ($post === null) {
                    continue;
                }

                $posts[] = $post;
            }

            $totalPages = (int) $response->header(
                'X-WP-TotalPages',
                $page
            );

            $page++;

        } while ($page <= $totalPages);

        return $posts;
    }

    public function getPost(int $postId): ?PostApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/posts/{$postId}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $postData = $response->json();
        if (! is_array($postData)) {
            return null;
        }

        return PostApiDto::fromApiResponse($postData);
    }

    public function createPost(array $data): ?array
    {
        try {
            $response = $this->client->post(
                '/wp-json/wp/v2/posts',
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $createdPost = $response->json();

        return is_array($createdPost)
            ? $createdPost
            : null;
    }

    public function createPosts(array $posts): array
    {
        $createdPosts = [];

        foreach ($posts as $post) {
            $createdPost = $this->createPost($post);
            if ($createdPost === null) {
                return [];
            }

            $createdPosts[] = $createdPost;
        }

        return $createdPosts;
    }

    public function updatePost(int $postId, array $data): ?array
    {
        try {
            $response = $this->client->post(
                "/wp-json/wp/v2/posts/{$postId}",
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $updatedPost = $response->json();

        return is_array($updatedPost)
            ? $updatedPost
            : null;
    }

    public function updatePosts(array $posts): array
    {
        $updatedPosts = [];

        foreach ($posts as $post) {
            if (
                ! is_array($post)
                || ! array_key_exists('id', $post)
                || ! array_key_exists('data', $post)
                || ! is_array($post['data'])
            ) {
                return [];
            }

            $updatedPost = $this->updatePost(
                (int) $post['id'],
                $post['data']
            );
            if ($updatedPost === null) {
                return [];
            }

            $updatedPosts[] = $updatedPost;
        }

        return $updatedPosts;
    }

    public function deletePost(int $postId): ?array
    {
        try {
            $response = $this->client->delete(
                "/wp-json/wp/v2/posts/{$postId}",
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

        $deletedPost = $response->json();

        return is_array($deletedPost)
            ? $deletedPost
            : null;
    }

    public function deletePosts(array $postIds): array
    {
        $deletedPosts = [];

        foreach ($postIds as $postId) {
            $deletedPost = $this->deletePost(
                (int) $postId
            );
            if ($deletedPost === null) {
                return [];
            }

            $deletedPosts[] = $deletedPost;
        }

        return $deletedPosts;
    }
}
