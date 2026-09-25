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
 * 記事のゴミ箱への移動・完全削除（重大な操作のため、必ず確認画面を挟む。WORDPRESS_API 22章、D-09-05）。
 */
class ArticleTrashController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleRepository $articles,
        protected ArticlePushService $pushService,
    ) {
    }

    public function confirm(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        return view('articles.trash', [
            'type'    => $type,
            'article' => $article,
            'force'   => $request->boolean('force'),
        ]);
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        $validated = $request->validate([
            'force'           => ['required', 'boolean'],
            'base_modified'   => ['nullable', 'date'],
            'confirmed'       => ['accepted'],
            // 完全削除は、さらに記事のスラッグを入力させる
            'confirm_slug'    => [$request->boolean('force') ? 'required' : 'nullable', 'string'],
        ]);

        if ($request->boolean('force') && $validated['confirm_slug'] !== $article->slug) {
            return back()->withErrors(['confirm_slug' => '入力したスラッグが記事のスラッグと一致しません。']);
        }

        try {
            $operation = $this->pushService->trash(
                $article,
                $request->boolean('force'),
                filled($validated['base_modified'] ?? null) ? Carbon::parse($validated['base_modified'], 'UTC') : null,
                $request->user()?->id
            );
        } catch (PushException $e) {
            return back()->withErrors(['push' => $e->getMessage()]);
        }

        return redirect()->route('push-operations.show', ['id' => $operation->id]);
    }
}
