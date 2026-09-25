<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Repositories\SyncRunRepository;

/**
 * 同期の記録（sync_runs・sync_run_resources）のDB確認画面。
 */
class SyncRunController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected SyncRunRepository $syncRunRepository,
    ) {
    }

    public function index()
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        return view('database.sync-runs.index', [
            'blog' => $blog,
            'runs' => $this->syncRunRepository->recentForBlog($blog->id, 100),
        ]);
    }
}
