<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Database\WordPressRecordController;
use App\Services\ServerStatusService;

/**
 * XServer情報（メニューの「情報 → XServer情報」。D-71）。読み取るだけで、DB への書き込み・削除はしない。
 * 全ブログ共通のため、ブログを選んでいなくても開ける。
 */
class ServerStatusController extends Controller
{
    public function index(ServerStatusService $status)
    {
        $database = $status->database();
        // サーバー環境の点検（D-88）で比べる、Web 側の PHP の版を記録する
        \App\Services\EnvironmentCheckService::rememberWebPhp();

        return view('server.index', [
            // サーバー環境の点検の最新の結果（D-88。定期実行 environment:check）
            'environment'    => \App\Models\EnvironmentCheck::orderByRaw("field(target, '" . implode("','", \App\Models\EnvironmentCheck::TARGETS) . "')")->get(),
            'environmentRun' => \App\Models\ScheduledTaskRun::where('task_key', 'environment:check')->latest('started_at')->latest('id')->first(),
            'machine'  => $status->machine(),
            'database' => $database,
            // ブログごとの内訳（件数は数えた値、容量は見積もり。D-71-08）。重いため、定期実行で計算した最新の結果を読む（D-71-09）
            'breakdown' => $status->latestBreakdown(),
            'wordpressTables' => $this->wordpressTables(),
            'others'   => $status->otherDatabases(),
            'prunable' => $status->prunable(),
            'queue'    => $status->queue(),
            'files'    => $status->files(),
        ]);
    }

    /**
     * DB（BlogOS）のうち、取り込んだ WordPress のデータのテーブル（テーブル名 => 名前・WordPress側で削除済みの件数）。
     * 取り込んだ WordPress のデータの画面と同じ形で出す。件数は、この画面の件数と同じく全ブログの分（D-71-07）
     *
     * @return array<string, array{label: string, deleted: int}>
     */
    protected function wordpressTables(): array
    {
        return collect(WordPressRecordController::definitions())->map(fn (array $definition) => [
            'label'   => $definition['label'],
            'deleted' => $definition['model']::whereNotNull('wordpress_deleted_at')->count(),
        ])->all();
    }
}
