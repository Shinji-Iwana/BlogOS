<?php

namespace App\Http\Controllers\Articles;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleDraftRepository;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use App\Support\LineDiff;
use Illuminate\Http\Request;

/**
 * 編集案の反映の確認と承認（ARCHITECTURE 13-5・18-2）。
 *
 * 反映は必ずこの確認画面で人が承認してから行う。公開の状態になる反映は、さらに明示的な確認を求める。
 */
class DraftPushController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected ArticlePushService $pushService,
    ) {
    }

    public function confirm(int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $article = $draft->article();
        $payload = $this->pushService->payload($draft);
        $current = $article ? $this->pushService->currentValues($article) : [];

        return view('drafts.push', [
            'blog'        => $blog,
            'draft'       => $draft,
            'article'     => $article,
            'payload'     => $payload,
            'current'     => $current,
            'contentDiff' => array_key_exists('content', $payload)
                ? (($diff = LineDiff::compute($current['content'] ?? '', $payload['content'])) === null ? null : LineDiff::compact($diff))
                : [],
            'willBePublic' => $this->pushService->willBePublic($draft),
            'locked'       => $draft->isLocked(),
        ]);
    }

    public function store(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $request->validate([
            'approved'         => ['accepted'],
            'confirmed_public' => [$this->pushService->willBePublic($draft) ? 'accepted' : 'nullable'],
        ], [
            'approved.accepted'         => '反映の内容を確認し、承認してください。',
            'confirmed_public.accepted' => '反映すると記事が公開されることを確認してください。',
        ]);

        try {
            $operation = $this->pushService->push($draft, $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['push' => $e->getMessage()]);
        }

        return redirect()->route('push-operations.show', ['id' => $operation->id]);
    }
}
