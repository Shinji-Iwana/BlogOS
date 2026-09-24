<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\PageHistoryRepository;

class PageHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected PageHistoryRepository $pageHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->pageHistoryRepository->getAll($blogId);

        return view('database.page-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
