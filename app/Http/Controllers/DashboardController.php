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

        $selectedBlog = $this->blogRepository->getSelectedOrFirst();

        if (!$selectedBlog) {
            $selectedBlog = $this->blogRepository->selectBlog(
                $blogs->first()->id
            );
        }

        return view(\App\Services\ThemeService::index(), [
            'blogs'        => $blogs,
            'selectedBlog' => $selectedBlog,
            'showBlogRegisterModal' => false,
        ]);
    }
}
