<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * BlogOSのAI機能の実行・出力の取り込みができない場合。メッセージは画面にそのまま表示する。
 */
class AiException extends RuntimeException
{
}
