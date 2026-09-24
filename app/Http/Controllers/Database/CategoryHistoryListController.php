<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\CategoryHistoryRepository;

class CategoryHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected CategoryHistoryRepository $categoryHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->categoryHistoryRepository->getAll($blogId);

        return view('database.category-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
