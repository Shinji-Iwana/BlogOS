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

    public function index(int $blogId, int $categoryId)
    {
        $categoryService = new CategoryService(
            $blogId,
            $this->blogRepository
        );

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
