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

    public function index()
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

        $taxonomyService = new TaxonomyService($blog);

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
