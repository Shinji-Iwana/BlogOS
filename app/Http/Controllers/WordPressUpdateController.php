<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Models\WordPressComponent;

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
            // 最後の確認（定期実行・即時実行の「WordPressの更新確認」の、一番新しい実行）
            'latestRun'  => \App\Models\ScheduledTaskRun::where('task_key', 'wordpress:check-updates')->latest('started_at')->latest('id')->first(),
            'adminUrl'   => rtrim($blog->home, '/') . '/wp-admin/update-core.php',
        ]);
    }

}
