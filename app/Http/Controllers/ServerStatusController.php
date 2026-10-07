<?php

namespace App\Http\Controllers;

use App\Services\ServerStatusService;

/**
 * サーバー情報（メニューの「情報 → サーバー情報」。D-71）。読み取るだけで、DB への書き込み・削除はしない。
 * 全ブログ共通のため、ブログを選んでいなくても開ける。
 */
class ServerStatusController extends Controller
{
    public function index(ServerStatusService $status)
    {
        return view('server.index', [
            'server'   => $status->server(),
            'database' => $status->database(),
            'prunable' => $status->prunable(),
            'queue'    => $status->queue(),
            'files'    => $status->files(),
        ]);
    }
}
