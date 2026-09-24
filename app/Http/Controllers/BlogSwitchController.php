<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlogSwitchController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function switch(Request $request): RedirectResponse
    {
        $blogId = (int) $request->input('blog_id');

        $this->blogRepository->updateSelected($blogId);

        return redirect()->route('home');
    }
}
