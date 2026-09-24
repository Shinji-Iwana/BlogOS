<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\MediaHistoryRepository;

class MediaHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected MediaHistoryRepository $mediaHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->mediaHistoryRepository->getAll($blogId);

        return view('database.media-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
