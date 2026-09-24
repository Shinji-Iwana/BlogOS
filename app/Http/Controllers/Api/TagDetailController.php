<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\TagService;

class TagDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $tagId)
    {
        $tagService = new TagService(
            $blogId,
            $this->blogRepository
        );

        $tag = $tagService->getTag(
            $tagId
        );

        return view('api.tag-detail', [
            'blogId' => $blogId,
            'tagId'  => $tagId,
            'tag'    => $tag,
        ]);
    }
}
