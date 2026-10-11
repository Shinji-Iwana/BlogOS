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
        return view('server.index', [
            'machine'  => $status->machine(),
            'database' => $status->database(),
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
