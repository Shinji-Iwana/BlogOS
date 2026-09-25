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

    public function index()
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

        $mediaService = new MediaService($blog);

        $medias = $mediaService->getMedias();

        $medias = $medias ?? [];

        $total = count($medias);

        return view('api.media-list', [
            'blogId' => $blogId,
            // 画面（api/media-list）は $media で一覧を受け取る
            'media'  => $medias,
            'total'  => $total,
        ]);
    }
}
