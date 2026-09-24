<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\TypeHistoryRepository;

class TypeHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected TypeHistoryRepository $typeHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->typeHistoryRepository->getAll($blogId);

        return view('database.type-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
