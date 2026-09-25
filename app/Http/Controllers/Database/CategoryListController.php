<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;

use App\Repositories\BlogRepository;
use App\Repositories\CategoryRepository;

class CategoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,

        protected CategoryRepository $categoryRepository
    ) {
    }

    public function index()
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();

        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $categories = $this->categoryRepository->getAll($blog->id);

        return view('database.category-list', [
            'blog'       => $blog,
            'categories' => $categories,
            'total'      => $categories->count(),
        ]);
    }
}
