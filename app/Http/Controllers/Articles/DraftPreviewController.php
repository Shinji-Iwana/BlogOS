<?php

namespace App\Http\Controllers\Articles;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleDraftRepository;
use App\Services\Articles\DraftPreviewException;
use App\Services\Articles\DraftPreviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 編集案のプレビュー：左に変更前、右に編集案を、実際のサイトの表示で並べる（D-28）。
 *
 * 表示するのは、保存済みの編集案の内容（編集画面で直した後は、保存してから開く）。
 */
class DraftPreviewController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected DraftPreviewService $preview,
    ) {
    }

    public function show(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $device = in_array($request->query('device'), DraftPreviewService::DEVICES, true) ? $request->query('device') : 'pc';

        return view('drafts.preview', [
            'blog'    => $blog,
            'draft'   => $draft,
            'article' => $draft->article(),
            'device'  => $device,
        ]);
    }

    /**
     * プレビューの枠（iframe）の中身。スクリプトを取り除いたページを返し、さらに CSP でスクリプトを止める
     */
    public function frame(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $validated = $request->validate([
            'side'   => ['required', Rule::in(DraftPreviewService::SIDES)],
            'device' => ['nullable', Rule::in(DraftPreviewService::DEVICES)],
        ]);

        try {
            $html = $this->preview->render($blog, $draft, $validated['side'], $validated['device'] ?? 'pc');
        } catch (DraftPreviewException $e) {
            $html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"></head>'
                . '<body style="font-family:sans-serif;padding:24px;color:#555;">' . e($e->getMessage()) . '</body></html>';
        }

        return response($html, 200, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            // ページの中のスクリプトを一切動かさない（アクセス解析・広告に数えないため）
            'Content-Security-Policy' => "script-src 'none'; object-src 'none'; frame-ancestors 'self'",
            'X-Robots-Tag'            => 'noindex, nofollow',
            'Cache-Control'           => 'no-store, private',
        ]);
    }
}
