<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogHistoryRepository;
use App\Repositories\BlogRepository;

class BlogHistoryDetailController extends Controller
{
    protected BlogHistoryRepository $blogHistoryRepository;
    protected BlogRepository $blogRepository;

    public function __construct(
        BlogHistoryRepository $blogHistoryRepository,
        BlogRepository $blogRepository
    ) {
        $this->blogHistoryRepository = $blogHistoryRepository;
        $this->blogRepository = $blogRepository;
    }

    public function show(int $id)
    {
        $histories = $this->blogHistoryRepository->getAll();

        $history = $histories->firstWhere('id', $id);

        $blog = null;

        if ($history !== null) {
            $blog = $this->blogRepository->findById(
                (int) $history->blog_id
            );
        }

        return view('database.blog-history-detail', [
            'history' => $history,
            'blog'    => $blog,
        ]);
    }
}
