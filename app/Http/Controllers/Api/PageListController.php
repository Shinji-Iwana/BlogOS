<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\PageService;

class PageListController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId)
    {
        $pageService = new PageService(
            $blogId,
            $this->blogRepository
        );

        $pages = $pageService->getPages();

        $pages = $pages ?? [];

        $total = count($pages);

        return view('api.page-list', [
            'blogId' => $blogId,
            'pages'  => $pages,
            'total'  => $total,
        ]);
    }
}
