<?php

namespace App\Clients\WordPress;

use RuntimeException;

/**
 * WordPress APIの取得に失敗したことを表す。
 *
 * 同期・反映で失敗した場合、応答本文の全文を記録するために保持する（D-10-03）。
 * 認証情報は含めない。
 */
class WordPressApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $status = null,
        public readonly ?string $body = null,
    ) {
        parent::__construct($message);
    }
}
