<?php

namespace App\Http\Controllers;

use App\Services\Notices\NoticeService;
use App\Support\HistoryPage;
use Illuminate\Http\Request;

/**
 * お知らせ（ヘッダーのお知らせのボタン・メニューの「履歴 → お知らせ」。D-74）。全ブログ共通のため、ブログを選んでいなくても開ける。
 */
class NoticeController extends Controller
{
    public function __construct(
        protected NoticeService $notices,
    ) {
    }

    public function index()
    {
        return view('notices.index', [
            'notices'   => $this->notices->paginate(HistoryPage::PER_PAGE),
            'checkedAt' => $this->notices->lastCheckedAt(),
            // ヘッダーの数（確認済みにした後、横の画面のパネルの中から、トップページのヘッダーの数も直すため）
            'openCount' => $this->notices->openCount(),
        ]);
    }

    /**
     * チェックを入れたお知らせを確認済みにする（一覧からは消さない）
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => '確認済みにするお知らせを選んでください。',
        ]);

        $count = $this->notices->confirm(array_map('intval', $validated['ids']), $request->user()?->id);

        return back()->with('status', "{$count}件のお知らせを確認済みにしました。");
    }
}
