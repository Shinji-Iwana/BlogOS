<?php

namespace App\Http\Controllers\Sync;

use App\Enums\SyncTrigger;
use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\Sync\SyncDispatcher;
use App\Services\Sync\SyncStatusService;
use Illuminate\Http\Request;

/**
 * 「今すぐ同期」（D-01-03）。
 *
 * Webリクエストの中では同期せず、Jobとして登録する（ARCHITECTURE 28章）。
 * 進み具合は、ダッシュボードが Api\SyncStatusController を定期的に読んで表示する。
 */
class SyncRunController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected SyncDispatcher $dispatcher,
        protected SyncStatusService $statusService,
    ) {
    }

    public function store(Request $request)
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        if ($blog->isArchived()) {
            return back()->withErrors(['sync' => 'アーカイブしたブログは同期できません。']);
        }

        $state = $this->statusService->forBlog($blog)['state'];

        if ($state !== 'idle') {
            return back()->withErrors([
                'sync' => $state === 'running' ? 'このブログの同期は既に実行中です。' : 'このブログの同期は既に開始待ちです。',
            ]);
        }

        $this->dispatcher->dispatch($blog->id, SyncTrigger::Manual, $request->user()?->id);

        return back()->with('status', '同期を開始しました。完了まで数分かかることがあります。');
    }
}
