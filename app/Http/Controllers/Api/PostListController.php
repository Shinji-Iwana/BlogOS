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

    public function index()
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

        $postService = new PostService($blog);

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
