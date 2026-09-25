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
        // 存在しないブログIDで例外（500）にならないよう、入力を検証する
        $validated = $request->validate([
            'blog_id' => ['required', 'integer', 'exists:blogs,id'],
        ]);

        $this->blogRepository->updateSelected((int) $validated['blog_id']);

        return redirect()->route('home');
    }
}
