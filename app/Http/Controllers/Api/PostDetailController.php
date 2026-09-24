<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\PostService;

class PostDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $postId)
    {
        $postService = new PostService(
            $blogId,
            $this->blogRepository
        );

        $post = $postService->getPost(
            $postId
        );

        return view('api.post-detail', [
            'blogId' => $blogId,
            'postId' => $postId,
            'post'   => $post,
        ]);
    }
}
