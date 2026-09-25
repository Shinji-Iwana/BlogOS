<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Services\Sync\SyncStatusService;
use App\Services\ThemeService;

class DashboardController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected SyncStatusService $syncStatusService,
    ) {
    }

    /**
     * トップページ。見た目は選択中のテーマで表示する（D-16-01）。
     *
     * ブログが1件もない場合は、テーマ側でブログ登録へ誘導する。
     * 選択中のブログがない場合は null のまま表示し、画面上部の切り替えから選ばせる（表示のためにDBを書き換えない）。
     * 同期の結果と未解決の問題は、DBから読んで表示する（D-01-05）。
     */
    public function index()
    {
        $selectedBlog = $this->blogRepository->findSelected();

        return view(ThemeService::index(), [
            'blogs'        => $this->blogRepository->getAll(),
            'selectedBlog' => $selectedBlog,
            'syncStatus'   => $selectedBlog ? $this->syncStatusService->forBlog($selectedBlog) : null,
        ]);
    }
}
