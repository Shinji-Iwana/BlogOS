<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\TagService;

class TagListController extends Controller
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

        $tagService = new TagService($blog);

        $tags = $tagService->getTags();

        $tags = $tags ?? [];

        $total = count($tags);

        return view('api.tag-list', [
            'blogId' => $blogId,
            'tags'   => $tags,
            'total'  => $total,
        ]);
    }
}
