<?php

namespace App\Services\WordPress;

use App\Repositories\BlogRepository;
use App\DTO\WordPress\MediaApiDto;
use Illuminate\Http\Client\ConnectionException;

class MediaService
{
    protected WordPressApiClient $client;

    public function __construct(int $blogId, BlogRepository $blogRepository)
    {
        $this->client = new WordPressApiClient(
            $blogId,
            $blogRepository
        );
    }

    public function getMedias(): ?array
    {
        $medias = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get(
                    '/wp-json/wp/v2/media',
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

            $pageMedias = $response->json();
            if (! is_array($pageMedias)) {
                return null;
            }

            foreach ($pageMedias as $pageMedia) {
                if (! is_array($pageMedia)) {
                    continue;
                }

                $media = MediaApiDto::fromApiResponse([
                    $pageMedia,
                ]);
                if ($media === null) {
                    continue;
                }

                $medias[] = $media;
            }

            $totalPages = (int) $response->header(
                'X-WP-TotalPages',
                $page
            );

            $page++;

        } while ($page <= $totalPages);

        return $medias;
    }

    public function getMedia(int $mediaId): ?MediaApiDto
    {
        try {
            $response = $this->client->get(
                "/wp-json/wp/v2/media/{$mediaId}"
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $mediaData = $response->json();
        if (! is_array($mediaData)) {
            return null;
        }

        return MediaApiDto::fromApiResponse([
            $mediaData,
        ]);
    }

    public function createMedia(string $filePath, array $data = []): ?array
    {
        if (! is_file($filePath)) {
            return null;
        }

        try {
            $response = $this->client->postMultipart(
                '/wp-json/wp/v2/media',
                $filePath,
                'file',
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $createdMedia = $response->json();

        return is_array($createdMedia)
            ? $createdMedia
            : null;
    }

    public function createMedias(array $medias): array
    {
        $createdMedias = [];

        foreach ($medias as $media) {
            if (
                ! is_array($media)
                || ! array_key_exists('filePath', $media)
                || ! array_key_exists('data', $media)
                || ! is_array($media['data'])
            ) {
                return [];
            }

            $createdMedia = $this->createMedia(
                $media['filePath'],
                $media['data']
            );
            if ($createdMedia === null) {
                return [];
            }

            $createdMedias[] = $createdMedia;
        }

        return $createdMedias;
    }

    public function updateMedia(int $mediaId, array $data): ?array
    {
        try {
            $response = $this->client->post(
                "/wp-json/wp/v2/media/{$mediaId}",
                $data
            );
        } catch (ConnectionException $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $updatedMedia = $response->json();

        return is_array($updatedMedia)
            ? $updatedMedia
            : null;
    }

    public function updateMedias(array $medias): array
    {
        $updatedMedias = [];

        foreach ($medias as $media) {
            if (
                ! is_array($media)
                || ! array_key_exists('id', $media)
                || ! array_key_exists('data', $media)
                || ! is_array($media['data'])
            ) {
                return [];
            }

            $updatedMedia = $this->updateMedia(
                (int) $media['id'],
                $media['data']
            );
            if ($updatedMedia === null) {
                return [];
            }

            $updatedMedias[] = $updatedMedia;
        }

        return $updatedMedias;
    }

    public function deleteMedia(int $mediaId): ?array
    {
        try {
            $response = $this->client->delete(
                "/wp-json/wp/v2/media/{$mediaId}",
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

        $deletedMedia = $response->json();

        return is_array($deletedMedia)
            ? $deletedMedia
            : null;
    }

    public function deleteMedias(array $mediaIds): array
    {
        $deletedMedias = [];

        foreach ($mediaIds as $mediaId) {
            $deletedMedia = $this->deleteMedia(
                (int) $mediaId
            );
            if ($deletedMedia === null) {
                return [];
            }

            $deletedMedias[] = $deletedMedia;
        }

        return $deletedMedias;
    }
}
