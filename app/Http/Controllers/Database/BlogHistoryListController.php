<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogHistoryRepository;

/**
 * ブログ情報の同期履歴（DB確認画面。D-63-16）。
 *
 * blogs（BlogOS側の情報）の履歴と、blog_settings（WordPressのサイト設定）の履歴を表示する。
 */
class BlogHistoryListController extends Controller
{
    /** 表示する最大の件数（表ごと） */
    public const LIMIT = 200;

    public function __construct(
        protected BlogHistoryRepository $blogHistoryRepository
    ) {
    }

    public function index()
    {
        $histories = $this->blogHistoryRepository->getAll(self::LIMIT);
        $settingHistories = $this->blogHistoryRepository->getSettingHistories(self::LIMIT);

        return view('database.blog-history-list', [
            'histories'        => $histories,
            'settingHistories' => $settingHistories,
            'limit'            => self::LIMIT,
        ]);
    }
}
