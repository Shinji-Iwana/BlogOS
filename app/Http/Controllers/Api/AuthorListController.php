<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\AuthorService;

class AuthorListController extends Controller
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

        $authorService = new AuthorService($blog);

        $authors = $authorService->getAuthors();

        $authors = $authors ?? [];

        $total = count($authors);

        return view('api.author-list', [
            'blogId'  => $blogId,
            // 画面（api/author-list）は $users で一覧を受け取る
            'users'   => $authors,
            'total'   => $total,
        ]);
    }
}
