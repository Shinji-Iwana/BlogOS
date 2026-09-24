<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\AuthorHistoryRepository;

class AuthorHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected AuthorHistoryRepository $authorHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->authorHistoryRepository->getAll($blogId);

        return view('database.author-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
