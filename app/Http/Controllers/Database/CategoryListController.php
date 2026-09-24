<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\CategoryRepository;

class CategoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,

        protected CategoryRepository $categoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $categories = $this->categoryRepository->getAll($blogId);

        return view('database.category-list', [
            'blog'       => $blog,
            'categories' => $categories,
            'total'      => $categories->count(),
        ]);
    }
}
