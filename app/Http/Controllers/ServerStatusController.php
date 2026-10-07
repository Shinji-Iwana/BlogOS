<?php

namespace App\Http\Controllers;

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
            'others'   => $status->otherDatabases(),
            'prunable' => $status->prunable(),
            'queue'    => $status->queue(),
            'files'    => $status->files(),
        ]);
    }
}
