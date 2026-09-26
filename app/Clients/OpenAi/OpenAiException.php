<?php

namespace App\Clients\OpenAi;

use RuntimeException;

/**
 * OpenAI APIの呼び出しに失敗した（通信エラー・HTTPエラー・途中で打ち切られた応答）。APIキーは含めない。
 */
class OpenAiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $body = null,
        // 途中で打ち切られた場合も料金はかかるため、トークン数を持つ（OpenAiClient::usage() の形）
        public readonly ?array $usage = null,
    ) {
        parent::__construct($message);
    }
}
