<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\TagHistoryRepository;

class TagHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected TagHistoryRepository $tagHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->tagHistoryRepository->getAll($blogId);

        return view('database.tag-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
