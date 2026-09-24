<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\MediaService;

class MediaListController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId)
    {
        $mediaService = new MediaService(
            $blogId,
            $this->blogRepository
        );

        $medias = $mediaService->getMedias();

        $medias = $medias ?? [];

        $total = count($medias);

        return view('api.media-list', [
            'blogId' => $blogId,
            'medias'  => $medias,
            'total'  => $total,
        ]);
    }
}
