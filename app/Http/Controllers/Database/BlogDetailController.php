<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;

class BlogDetailController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function show(int $id)
    {
        $blog = $this->blogRepository->findById($id);

        return view('database.blog-detail', [
            'blog' => $blog,
        ]);
    }
}
