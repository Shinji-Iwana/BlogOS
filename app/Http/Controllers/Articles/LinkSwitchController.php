<?php

namespace App\Http\Controllers\Articles;

use App\Enums\DraftState;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\ArticleDraft;
use App\Services\Articles\LinkSwitchService;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use Illuminate\Http\Request;

/**
 * 公開された記事へのリンクの切り替え（D-39）。BlogOS が自動で作った編集案を確認し、まとめて反映する。
 */
class LinkSwitchController extends Controller
{
    use UsesSelectedBlog;

    /**
     * 1回のまとめて反映の上限（WordPress への送信を続けすぎないため）
     */
    protected const PUSH_LIMIT = 20;

    public function index(LinkSwitchService $service)
    {
        $blog = $this->selectedBlog();

        return view('drafts.link-switch', [
            'blog'    => $blog,
            'drafts'  => $this->drafts($blog->id)->load(['post:id,title_raw', 'page:id,title_raw']),
            // 作業中の編集案があるため、作っていない記事
            'skipped' => array_values(array_filter($service->pending($blog), fn ($row) => $row['active_draft'] !== null && $row['active_draft']->auto_reason !== LinkSwitchService::REASON)),
        ]);
    }

    /**
     * 今すぐ調べて、編集案を作る（ふだんは同期・反映の後に自動で作る）
     */
    public function create(Request $request, LinkSwitchService $service)
    {
        $blog = $this->selectedBlog();
        $created = $service->createDrafts($blog, $request->user()?->id);

        return redirect()->route('drafts.link-switch')->with('status', $created > 0 ? "リンクに切り替える編集案を {$created}件作りました。" : 'リンクに切り替えられる記事はありませんでした。');
    }

    /**
     * チェックした編集案をまとめて反映する（人の承認）
     */
    public function push(Request $request, ArticlePushService $pushService)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate(['selected' => ['required', 'array'], 'selected.*' => ['integer']]);

        $drafts = $this->drafts($blog->id)->whereIn('id', array_map('intval', $validated['selected']))->take(self::PUSH_LIMIT);
        $pushed = 0;
        $errors = [];
        foreach ($drafts as $draft) {
            try {
                $pushService->push($draft, $request->user()?->id);
                $pushed++;
            } catch (PushException $e) {
                $errors[] = "編集案 #{$draft->id}：{$e->getMessage()}";
            }
        }

        $redirect = redirect()->route('drafts.link-switch')->with('status', "{$pushed}件を反映しました。" . (count($validated['selected']) > self::PUSH_LIMIT ? '（1回に ' . self::PUSH_LIMIT . '件までです。残りは、もう一度反映してください）' : ''));

        return $errors === [] ? $redirect : $redirect->withErrors(['push' => implode("\n", $errors)]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ArticleDraft>
     */
    protected function drafts(int $blogId)
    {
        return ArticleDraft::where('blog_id', $blogId)->where('auto_reason', LinkSwitchService::REASON)
            ->whereIn('state', [DraftState::Editing->value, DraftState::Review->value])->orderBy('id')->get();
    }
}
