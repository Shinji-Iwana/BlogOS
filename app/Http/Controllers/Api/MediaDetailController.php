<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\MediaService;

class MediaDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $mediaId)
    {
        $mediaService = new MediaService(
            $blogId,
            $this->blogRepository
        );

        $media = $mediaService->getMedia(
            $mediaId
        );

        return view('api.media-detail', [
            'blogId'  => $blogId,
            'mediaId' => $mediaId,
            'media'   => $media,
        ]);
    }
}
