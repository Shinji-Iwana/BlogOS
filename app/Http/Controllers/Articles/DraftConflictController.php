<?php

namespace App\Http\Controllers\Articles;

use App\Clients\WordPress\WordPressApiException;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleDraftRepository;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use App\Support\LineDiff;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 競合の解消（WORDPRESS_API 21-2、D-15-03）。
 *
 * WordPressの最新の内容と編集案の差分を示し、人が「取り込む・破棄する・上書きする」から選ぶ。
 * 最新の内容を示すため、この画面ではWordPress APIから記事を取得する（ARCHITECTURE 3-4）。
 */
class DraftConflictController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected ArticlePushService $pushService,
    ) {
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null || $draft->isNewArticle(), 404);

        try {
            $latest = $this->pushService->fetchLatest($draft);
        } catch (WordPressApiException|ConnectionException|PushException $e) {
            return redirect()->route('drafts.edit', ['id' => $id])->withErrors(['conflict' => "WordPressから最新の内容を取得できませんでした：{$e->getMessage()}"]);
        }

        $wordpress = $this->pushService->valuesFromApi($latest);
        $draftValues = $this->draftValues($draft);

        return view('drafts.conflict', [
            'draft'          => $draft,
            'article'        => $draft->article(),
            'latestModified' => $latest['modified_gmt'] ?? null,
            'wordpress'      => $wordpress,
            'draftValues'    => $draftValues,
            'contentDiff'    => ($diff = LineDiff::compute($wordpress['content'] ?? '', $draftValues['content'] ?? '')) === null ? null : LineDiff::compact($diff),
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null || $draft->isNewArticle(), 404);

        $validated = $request->validate([
            'action'    => ['required', Rule::in(['take_in', 'discard', 'overwrite'])],
            'confirmed' => [$request->input('action') === 'take_in' ? 'nullable' : 'accepted'],
        ], [
            'confirmed.accepted' => 'この対応の影響を確認してください。',
        ]);

        try {
            $this->pushService->resolveConflict($draft, $validated['action'], $request->user()?->id);
        } catch (PushException|WordPressApiException|ConnectionException $e) {
            return back()->withErrors(['conflict' => $e->getMessage()]);
        }

        return match ($validated['action']) {
            'take_in'   => redirect()->route('drafts.edit', ['id' => $id])->with('status', 'WordPressの変更をDBに取り込み、編集の起点を最新にしました。差分を見て編集案を直してください。'),
            'discard'   => redirect()->route('drafts.edit', ['id' => $id])->with('status', '編集案を破棄し、WordPressの最新の内容をDBに取り込みました。'),
            'overwrite' => redirect()->route('drafts.push.confirm', ['id' => $id])->with('status', 'WordPressの最新の内容をDBに取り込みました。編集案で上書きする内容を確認してください。'),
        };
    }

    /**
     * 編集案の値（送る項目の形）
     */
    protected function draftValues($draft): array
    {
        return array_filter([
            'title'          => $draft->title_raw,
            'content'        => $draft->content_raw,
            'excerpt'        => $draft->excerpt_raw,
            'meta_description' => $draft->meta_description,
            'slug'           => $draft->slug,
            'status'         => $draft->status,
            'featured_media' => $draft->wordpress_featured_media_id,
            'categories'     => $draft->wordpress_category_ids,
            'tags'           => $draft->wordpress_tag_ids,
        ], fn ($value) => $value !== null);
    }
}
