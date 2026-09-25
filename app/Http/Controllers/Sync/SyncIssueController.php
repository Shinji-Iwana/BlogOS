<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\SyncIssue;
use App\Repositories\BlogRepository;
use App\Repositories\SyncIssueRepository;
use Illuminate\Http\Request;

/**
 * 同期で見つかった、人の対応が必要な問題（sync_issues。BLOGOS_DATABASE.md 10-3、D-15-08）。
 *
 * 問題そのものはWordPress側・BlogOS側で対応し、この画面では対応した内容を記録して「解決済み」にする。
 */
class SyncIssueController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected SyncIssueRepository $issueRepository,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $showResolved = $request->boolean('resolved');

        return view('sync.issues.index', [
            'blog'         => $blog,
            'showResolved' => $showResolved,
            'issues'       => $showResolved
                ? $this->issueRepository->resolvedForBlog($blog->id, 200)
                : $this->issueRepository->unresolvedForBlog($blog->id),
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $issue = $this->issueRepository->findForBlog($blog->id, $id);
        abort_if($issue === null || $issue->resolved_at !== null, 404);

        $validated = $request->validate([
            'resolution' => ['required', 'string', 'max:2000'],
        ]);

        $this->issueRepository->resolve($issue, $request->user()?->id, $validated['resolution']);

        return redirect()->route('sync.issues.index')->with('status', '解決済みにしました。');
    }
}
