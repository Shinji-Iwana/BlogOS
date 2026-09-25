<?php

namespace App\Http\Middleware;

use App\Repositories\BlogRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 更新系の画面で、画面を表示した時点のブログと、現在の選択中のブログが一致することを確認する（D-02-05）。
 *
 * 別のタブでブログを切り替えた後に、古い画面から送信して別のブログを更新してしまうことを防ぐ。
 * フォームには resources/views/partials/selected-blog-field.blade.php で selected_blog_id を含める。
 */
class EnsureSelectedBlog
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $selectedBlog = $this->blogRepository->findSelected();

        if ($selectedBlog === null || (int) $request->input('selected_blog_id') !== $selectedBlog->id) {
            return redirect()->route('home')->withErrors([
                'selected_blog_id' => '画面を開いた後に、選択中のブログが切り替わりました。画面を開き直してから操作してください。',
            ]);
        }

        return $next($request);
    }
}
