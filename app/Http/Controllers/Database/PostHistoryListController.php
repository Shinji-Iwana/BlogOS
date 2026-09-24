<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\PostHistoryRepository;

class PostHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected PostHistoryRepository $postHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->postHistoryRepository->getAll($blogId);

        return view('database.post-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
