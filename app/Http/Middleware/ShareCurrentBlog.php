<?php

namespace App\Http\Middleware;

use App\Repositories\BlogRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\View;

class ShareCurrentBlog
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $blogs = $this->blogRepository->getAll();

        $selectedBlog = $this->blogRepository->getSelectedOrFirst();

        View::share([
            'blogs'        => $blogs,
            'selectedBlog' => $selectedBlog,
        ]);

        return $next($request);
    }
}
