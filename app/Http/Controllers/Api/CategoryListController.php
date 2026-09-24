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

    public function index(int $blogId)
    {
        $categoryService = new CategoryService(
            $blogId,
            $this->blogRepository
        );

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
