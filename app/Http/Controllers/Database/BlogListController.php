<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;

class BlogListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function index()
    {
        $blogs = $this->blogRepository->getAll();

        return view('database.blog-list', [
            'blogs' => $blogs,
            'total' => $blogs->count(),
        ]);
    }
}
