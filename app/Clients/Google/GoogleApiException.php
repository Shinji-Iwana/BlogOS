<?php

namespace App\Clients\Google;

use RuntimeException;

/**
 * Google APIの呼び出しに失敗した（通信エラー・HTTPエラー・想定しない応答）。
 * 応答本文は全文を持ち、取得の実行記録・同期の問題に保存する（D-10-03）。トークンは含めない。
 */
class GoogleApiException extends RuntimeException
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

    /**
     * 更新用のトークンが無効になった（取り消された・失効した）。Googleアカウントの再接続が必要
     */
    public function isInvalidGrant(): bool
    {
        return $this->status === 400 && str_contains((string) $this->body, 'invalid_grant');
    }
}
