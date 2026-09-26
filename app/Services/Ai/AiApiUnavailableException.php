<?php

namespace App\Services\Ai;

/**
 * API実行ができない（APIキーがない・月の費用の上限を超える）。
 * まとめて実行では、この例外で残りの記事の実行を止める（D-25-03）。
 */
class AiApiUnavailableException extends AiException
{
}
