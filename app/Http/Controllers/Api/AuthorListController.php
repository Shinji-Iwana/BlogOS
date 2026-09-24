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

    public function index(int $blogId)
    {
        $authorService = new AuthorService(
            $blogId,
            $this->blogRepository
        );

        $authors = $authorService->getAuthors();

        $authors = $authors ?? [];

        $total = count($authors);

        return view('api.author-list', [
            'blogId'  => $blogId,
            'authors' => $authors,
            'total'   => $total,
        ]);
    }
}
