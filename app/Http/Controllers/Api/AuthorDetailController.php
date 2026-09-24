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

    public function index(int $blogId, int $authorId)
    {
        $authorService = new AuthorService(
            $blogId,
            $this->blogRepository
        );

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
