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

    public function index(int $mediaId)
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

        $mediaService = new MediaService($blog);

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
