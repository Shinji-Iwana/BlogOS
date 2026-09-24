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

    public function index(int $blogId)
    {
        $tagService = new TagService(
            $blogId,
            $this->blogRepository
        );

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
