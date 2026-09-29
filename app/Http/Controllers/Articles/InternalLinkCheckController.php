<?php

namespace App\Http\Controllers\Articles;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Services\Articles\InternalLinkChecker;

/**
 * ブログ全体の内部リンクの確認（D-42）。同期で取り出した内部リンクから、リンク切れ・古い URL・孤立記事などを一覧にする。
 */
class InternalLinkCheckController extends Controller
{
    use UsesSelectedBlog;

    public function index(InternalLinkChecker $checker)
    {
        $blog = $this->selectedBlog();
        $result = $checker->check($blog);

        return view('articles.link-check', [
            'blog'     => $blog,
            'kinds'    => InternalLinkChecker::KINDS,
            'counts'   => $checker->counts($blog),
            'links'    => collect($result['links'])->groupBy('kind'),
            'articles' => collect($result['articles'])->groupBy('kind'),
        ]);
    }
}
