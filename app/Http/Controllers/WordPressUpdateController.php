<?php

namespace App\Http\Controllers;

use App\Clients\WordPress\WordPressApiException;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Models\WordPressComponent;
use App\Services\WordPress\WordPressUpdateCheckService;

/**
 * WordPress 本体・プラグイン・テーマの更新（D-38）。確認だけで、更新は WordPress の管理画面で行う。
 */
class WordPressUpdateController extends Controller
{
    use UsesSelectedBlog;

    public function index()
    {
        $blog = $this->selectedBlog();
        $components = WordPressComponent::where('blog_id', $blog->id)->orderByRaw("field(type, 'core', 'plugin', 'theme')")->orderBy('name')->get();

        return view('wordpress-updates.index', [
            'blog'       => $blog,
            'components' => $components,
            'checkedAt'  => $components->max('checked_at'),
            'adminUrl'   => rtrim($blog->home, '/') . '/wp-admin/update-core.php',
        ]);
    }

    public function check(WordPressUpdateCheckService $service)
    {
        $blog = $this->selectedBlog();

        try {
            $result = $service->check($blog);
        } catch (WordPressApiException $e) {
            return back()->withErrors(['wordpress' => "確認できませんでした：{$e->getMessage()}（プラグイン・テーマの一覧は、管理者の権限が必要です）"]);
        }

        return redirect()->route('wordpress-updates.index')->with('status', "{$result['checked']}件を確認しました（更新あり {$result['updates']}件・公開停止 {$result['closed']}件）。");
    }
}
