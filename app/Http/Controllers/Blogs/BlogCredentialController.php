<?php

namespace App\Http\Controllers\Blogs;

use App\Http\Controllers\Controller;
use App\Repositories\BlogCredentialRepository;
use App\Repositories\BlogRepository;
use App\Services\Blogs\BlogCredentialService;
use App\Services\Blogs\BlogInspectionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

/**
 * 選択中のブログの認証情報（D-03-02、D-03-03）。
 *
 * 登録済みの Application Password は画面に再表示しない。変更は上書きだけとする。
 */
class BlogCredentialController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected BlogCredentialRepository $blogCredentialRepository,
        protected BlogCredentialService $credentialService
    ) {
    }

    public function edit()
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        return view('blogs.credentials.edit', [
            'blog'       => $blog,
            'credential' => $this->blogCredentialRepository->findForBlog($blog->id),
        ]);
    }

    public function update(Request $request)
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $validated = $request->validate([
            'username'             => ['required', 'string', 'max:255'],
            'application_password' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->credentialService->update($blog, $validated['username'], $validated['application_password']);
        } catch (BlogInspectionException|ConnectionException $e) {
            return back()
                ->withErrors(['application_password' => $this->message($e)])
                ->withInput($request->only('username'));
        }

        return redirect()->route('blogs.credentials.edit')->with('status', '認証情報を更新しました（接続確認に成功）。');
    }

    public function verify()
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        try {
            $this->credentialService->verify($blog);
        } catch (BlogInspectionException|ConnectionException $e) {
            return back()->withErrors(['verify' => $this->message($e)]);
        }

        return back()->with('status', '接続確認に成功しました。');
    }

    protected function message(\Throwable $e): string
    {
        return $e instanceof BlogInspectionException
            ? $e->getMessage()
            : 'WordPressに接続できませんでした。時間をおいて再度お試しください。';
    }
}
