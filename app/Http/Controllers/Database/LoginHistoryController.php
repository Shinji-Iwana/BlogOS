<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginHistoryService;
use App\Support\HistoryPage;

/**
 * ログイン履歴の確認画面（BLOGOS_DECISIONS.md D-17-03）。
 *
 * 不正なログインの有無を確認するため、成功・失敗・ログアウトを新しい順に表示する。
 */
class LoginHistoryController extends Controller
{
    public function __construct(
        protected LoginHistoryService $loginHistoryService
    ) {
    }

    public function index()
    {
        return view('database.login-histories.index', [
            'histories' => $this->loginHistoryService->paginateLatest(HistoryPage::PER_PAGE),
        ]);
    }
}
