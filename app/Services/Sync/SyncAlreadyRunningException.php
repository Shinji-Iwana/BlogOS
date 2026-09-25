<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * 同じブログの同期が既に実行中であることを表す（ブログ単位のロック。ARCHITECTURE 28章）。
 */
class SyncAlreadyRunningException extends RuntimeException
{
}
