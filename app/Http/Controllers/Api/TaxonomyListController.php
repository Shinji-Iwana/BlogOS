<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\TaxonomyService;

class TaxonomyListController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId)
    {
        $taxonomyService = new TaxonomyService(
            $blogId,
            $this->blogRepository
        );

        $taxonomies = $taxonomyService->getTaxonomies();

        $taxonomies = $taxonomies ?? [];

        $total = count($taxonomies);

        return view('api.taxonomy-list', [
            'blogId'     => $blogId,
            'taxonomies' => $taxonomies,
            'total'      => $total,
        ]);
    }
}
