<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * 反映を始められない場合（編集案の状態・ロック・同期中など）。メッセージは画面にそのまま表示する。
 */
class PushException extends RuntimeException
{
}
