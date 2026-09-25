<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginHistoryService;

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
        $histories = $this->loginHistoryService->getLatest(200);

        return view('database.login-histories.index', [
            'histories' => $histories,
        ]);
    }
}
