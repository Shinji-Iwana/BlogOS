<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogHistoryRepository;
use App\Support\HistoryPage;

/**
 * ブログ情報の同期履歴（DB確認画面。D-63-16）。
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
        // 2つの表は、別々にページを送る（ページの番号の名前を分ける）
        return view('database.blog-history-list', [
            'histories'        => $this->blogHistoryRepository->paginate(HistoryPage::PER_PAGE, 'page')->withQueryString(),
            'settingHistories' => $this->blogHistoryRepository->paginateSettingHistories(HistoryPage::PER_PAGE, 'settings_page')->withQueryString(),
        ]);
    }
}
