<?php

namespace App\Http\Controllers\Articles;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleRepository;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 記事の完全削除（WORDPRESS_API 22章、D-09-05）。
 * 確認は、記事の詳細の共通の削除のポップアップで行う（ID の入力とチェック。確認の画面はなくした。D-125-01）。
 * BlogOS からゴミ箱へ移動する機能は、なくした（D-124-01）。
 */
class ArticleDeleteController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleRepository $articles,
        protected ArticlePushService $pushService,
    ) {
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        $validated = $request->validate([
            'base_modified' => ['nullable', 'date'],
            'confirmed'     => ['accepted'],
            // 確認のため、記事の WordPress ID を入力させる（スラッグが空の記事もあるため。D-125-01）
            'confirm_id'    => ['required', 'string'],
        ]);

        // はじいたときは、記事の詳細に戻り、画面の上にエラーを出す
        if (trim((string) $validated['confirm_id']) !== (string) $article->wordpress_id) {
            return back()->withErrors(['confirm_id' => '入力した ID が、記事の ID と一致しません。']);
        }

        try {
            $operation = $this->pushService->delete(
                $article,
                filled($validated['base_modified'] ?? null) ? Carbon::parse($validated['base_modified'], 'UTC') : null,
                $request->user()?->id
            );
        } catch (PushException $e) {
            return back()->withErrors(['push' => $e->getMessage()]);
        }

        return redirect()->route('push-operations.show', ['id' => $operation->id]);
    }
}
