<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\StatusHistoryRepository;

class StatusHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected StatusHistoryRepository $statusHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->statusHistoryRepository->getAll($blogId);

        return view('database.status-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
