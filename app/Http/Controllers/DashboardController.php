<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardDataService;
use App\Services\Notices\NoticeService;
use Illuminate\Http\Request;
use App\Services\Dashboard\DashboardStatusService;
use App\Support\DashboardLinks;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardDataService $dataService,
        protected DashboardStatusService $statusService,
    ) {
    }

    /**
     * トップページ。見た目は選んだテーマで表示する（テーマに dashboard/index があれば、そちら。D-16-01・D-49）。
     *
     * ブログが1件もない場合は、テーマ側でブログ登録へ誘導する。
     * 選択中のブログがない場合は null のまま表示し、画面上部の切り替えから選ばせる（表示のためにDBを書き換えない）。
     * 同期の結果と未解決の問題は、DBから読んで表示する（D-01-05）。
     * お知らせのデータから、領域ごとの状態のパネルと BlogOS 全体の状態を出し、各画面への入口（DashboardLinks）と合わせて渡す（D-49-07）。
     * データは DashboardDataService（音声の「今の状況」と共通。D-58）。
     */
    public function index(Request $request, NoticeService $notices)
    {
        // お知らせの記録を更新する（D-74。お知らせの失敗で、トップページを止めない）
        try {
            $notices->refresh();
        } catch (\Throwable $e) {
            report($e);
        }

        $data = $this->dataService->notices();

        // 領域ごとの状態（ironman はパネルとアークリアクターの色で表す）と、各画面への入口
        $panels = $this->dataService->panels($data);

        return view('dashboard.index', $data + [
            'statusPanels' => $panels,
            'statusCounts' => $this->statusService->counts($panels),
            'systemState'  => $this->statusService->overall($panels),
            'systemBusy'   => $this->statusService->busy($data),
            'linkGroups'   => DashboardLinks::groups($data['selectedBlog'] !== null),
            'noticePopup'  => $this->noticePopup($request, $notices),
        ]);
    }

    /**
     * 未確認のお知らせのポップアップ（D-74）。変動も解消もしていない未確認のお知らせがあり、
     * ログインしてから見せていない（このログインで、まだ見せていないお知らせがある）ときだけ開く
     *
     * @return array{count: int, notices: \Illuminate\Support\Collection}|null
     */
    protected function noticePopup(Request $request, NoticeService $notices): ?array
    {
        $open = $notices->open(5);
        if ($open->isEmpty()) {
            return null;
        }

        // このログインで見せた一番新しいお知らせ（ログインし直すと、セッションが新しくなり、また開く）
        $latestId = (int) \App\Models\Notice::open()->max('id');
        if ($latestId <= (int) $request->session()->get('notices.popup_shown', 0)) {
            return null;
        }
        $request->session()->put('notices.popup_shown', $latestId);

        return ['count' => $notices->openCount(), 'notices' => $open];
    }
}
