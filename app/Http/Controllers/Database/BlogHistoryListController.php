<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogHistoryRepository;

class BlogHistoryListController extends Controller
{
    public function __construct(
        protected BlogHistoryRepository $blogHistoryRepository
    ) {
    }

    public function index()
    {
        $histories = $this->blogHistoryRepository->getAll();

        return view('database.blog-history-list', [
            'histories' => $histories,
            'total' => $histories->count(),
        ]);
    }
}
