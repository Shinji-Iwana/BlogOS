<?php

namespace App\Clients\Google;

use RuntimeException;

/**
 * PageSpeed Insights API の失敗（D-78）。status は HTTP の状態コード（接続できなかったときは 0）
 */
class PageSpeedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
