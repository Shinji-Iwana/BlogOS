<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\TaxonomyHistoryRepository;

class TaxonomyHistoryListController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected TaxonomyHistoryRepository $taxonomyHistoryRepository
    ) {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $histories = $this->taxonomyHistoryRepository->getAll($blogId);

        return view('database.taxonomy-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
