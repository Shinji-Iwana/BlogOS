<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\CategoryService;

class CategoryListController extends Controller
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

        $categoryService = new CategoryService($blog);

        $categories = $categoryService->getCategories();

        $categories = $categories ?? [];

        $total = count($categories);

        return view('api.category-list', [
            'blogId'     => $blogId,
            'categories' => $categories,
            'total'      => $total,
        ]);
    }
}
