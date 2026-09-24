<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\TaxonomyService;

class TaxonomyDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $taxonomyId)
    {
        $taxonomyService = new TaxonomyService(
            $blogId,
            $this->blogRepository
        );

        $taxonomy = $taxonomyService->getTaxonomy(
            $taxonomyId
        );

        return view('api.taxonomy-detail', [
            'blogId'     => $blogId,
            'taxonomyId' => $taxonomyId,
            'taxonomy'   => $taxonomy,
        ]);
    }
}
