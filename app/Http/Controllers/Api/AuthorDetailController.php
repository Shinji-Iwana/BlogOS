<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\AuthorService;

class AuthorDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $authorId)
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

        $authorService = new AuthorService($blog);

        $author = $authorService->getAuthor(
            $authorId
        );

        return view('api.author-detail', [
            'blogId'   => $blogId,
            'authorId' => $authorId,
            'author'   => $author,
        ]);
    }
}
