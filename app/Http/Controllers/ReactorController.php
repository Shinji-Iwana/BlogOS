<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardDataService;
use App\Services\Dashboard\DashboardStatusService;
use Illuminate\Http\JsonResponse;

/**
 * アークリアクター（ironman テーマ）の状態と動き（D-76）。
 */
class ReactorController extends Controller
{
    /**
     * 全体の状態（色）と AI の実行中か（JSON）。トップページを開いている間、js/script.js が 10秒ごとに読み、
     * 画面を開いたままで、アークリアクターと SYSTEM STATUS の帯を切り替える。DB を読むだけで、WordPress API は呼ばない
     */
    public function status(DashboardDataService $dataService, DashboardStatusService $statusService): JsonResponse
    {
        $data = $dataService->notices();
        $panels = $dataService->panels($data);

        return response()->json([
            'state'  => $statusService->overall($panels),
            'busy'   => $statusService->busy($data),
            'counts' => $statusService->counts($panels),
        ]);
    }

    /**
     * 画面「アークリアクターの動き」：状態ごとの動きを並べて見比べる（色は画面の上で選ぶ）。
     * テーマの選択（ポップアップ）の、ironman のアニメーションの設定から開く。どのテーマを選んでいても、ironman の見た目で出す
     */
    public function compare()
    {
        return view('reactor.compare');
    }
}
