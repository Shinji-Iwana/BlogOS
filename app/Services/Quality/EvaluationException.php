<?php

namespace App\Services\Quality;

use RuntimeException;

/**
 * 評価を保存・確定できない場合。メッセージは画面にそのまま表示する。
 */
class EvaluationException extends RuntimeException
{
}
