<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\PageService;

class PageDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $pageId)
    {
        $pageService = new PageService(
            $blogId,
            $this->blogRepository
        );

        $page = $pageService->getPage(
            $pageId
        );

        return view('api.page-detail', [
            'blogId' => $blogId,
            'pageId' => $pageId,
            'page'   => $page,
        ]);
    }
}
