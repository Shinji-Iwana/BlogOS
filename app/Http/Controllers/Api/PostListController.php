<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\PostService;

class PostListController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId)
    {
        $postService = new PostService(
            $blogId,
            $this->blogRepository
        );

        $posts = $postService->getPosts();

        $posts = $posts ?? [];

        $total = count($posts);

        return view('api.post-list', [
            'blogId' => $blogId,
            'posts'  => $posts,
            'total'  => $total,
        ]);
    }
}
