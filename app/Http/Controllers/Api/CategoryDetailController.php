<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\CategoryService;

class CategoryDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $categoryId)
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

        $categoryService = new CategoryService($blog);

        $category = $categoryService->getCategory(
            $categoryId
        );

        return view('api.category-detail', [
            'blogId'     => $blogId,
            'categoryId' => $categoryId,
            'category'   => $category,
        ]);
    }
}
