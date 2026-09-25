<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;

class DashboardController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function index()
    {
        $blogs = $this->blogRepository->getAll();

        if ($blogs->isEmpty()) {
            return view(\App\Services\ThemeService::index(), [
                'blogs' => $blogs,
                'selectedBlog' => null,
                'showBlogRegisterModal' => true,
            ]);
        }

        // 選択中のブログがない場合は null のまま表示し、画面上部の切り替えから選ばせる。
        // 表示のためにDBを書き換えない。
        $selectedBlog = $this->blogRepository->findSelected();

        return view(\App\Services\ThemeService::index(), [
            'blogs'        => $blogs,
            'selectedBlog' => $selectedBlog,
            'showBlogRegisterModal' => false,
        ]);
    }
}
