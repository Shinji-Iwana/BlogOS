<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardDataService;
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
    public function index()
    {
        $data = $this->dataService->notices();

        // 領域ごとの状態（ironman はパネルとアークリアクターの色で表す）と、各画面への入口
        $panels = $this->dataService->panels($data);

        return view('dashboard.index', $data + [
            'statusPanels' => $panels,
            'statusCounts' => $this->statusService->counts($panels),
            'systemState'  => $this->statusService->overall($panels),
            'systemBusy'   => $this->statusService->busy($data),
            'linkGroups'   => DashboardLinks::groups($data['selectedBlog'] !== null),
        ]);
    }
}
