<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogHistoryRepository;

/**
 * ブログ変更履歴一覧（DB確認画面）。
 *
 * blogs（BlogOS側の情報）の履歴と、blog_settings（WordPressのサイト設定）の履歴を表示する。
 */
class BlogHistoryListController extends Controller
{
    public function __construct(
        protected BlogHistoryRepository $blogHistoryRepository
    ) {
    }

    public function index()
    {
        $histories = $this->blogHistoryRepository->getAll();
        $settingHistories = $this->blogHistoryRepository->getSettingHistories();

        return view('database.blog-history-list', [
            'histories'        => $histories,
            'settingHistories' => $settingHistories,
        ]);
    }
}
