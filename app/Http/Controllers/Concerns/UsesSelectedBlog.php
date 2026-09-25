<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Blog;
use App\Repositories\BlogRepository;

/**
 * 選択中のブログを対象とする画面（D-02-05）。選択されていなければ 404 とする。
 */
trait UsesSelectedBlog
{
    protected function selectedBlog(): Blog
    {
        $blog = app(BlogRepository::class)->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        return $blog;
    }
}
