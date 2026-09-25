<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogCredentialRepository;
use App\Repositories\BlogRepository;
use App\Repositories\BlogSettingRepository;

/**
 * ブログ詳細（DB確認画面）。
 */
class BlogDetailController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected BlogSettingRepository $blogSettingRepository,
        protected BlogCredentialRepository $blogCredentialRepository
    ) {
    }

    public function show(int $id)
    {
        $blog = $this->blogRepository->findById($id);

        return view('database.blog-detail', [
            'blog'       => $blog,
            'settings'   => $blog ? $this->blogSettingRepository->getForBlog($blog->id) : collect(),
            'credential' => $blog ? $this->blogCredentialRepository->findForBlog($blog->id) : null,
        ]);
    }
}
