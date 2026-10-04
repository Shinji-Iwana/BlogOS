<?php

namespace App\Http\Controllers\Terms;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Services\Terms\CategoryTreeService;

/**
 * カテゴリの一覧（D-53）。更新は、各カテゴリの編集の画面（TermController）で行う。
 */
class CategoryController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected CategoryTreeService $tree,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        return view('terms.categories', [
            'blog'       => $blog,
            'categories' => $this->tree->tree($blog),
        ]);
    }
}
