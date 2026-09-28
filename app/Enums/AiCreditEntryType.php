<?php

namespace App\Enums;

/**
 * OpenAI の残高の記録の種類（ai_credit_entries.type）。D-31-04。
 */
enum AiCreditEntryType: string
{
    // OpenAI の画面で見た残高（Credit balance）。残高の見込みの起点になる
    case Balance = 'balance';

    // 課金（チャージ）した額
    case Purchase = 'purchase';

    public function label(): string
    {
        return match ($this) {
            self::Balance  => '残高の確認',
            self::Purchase => '課金',
        };
    }
}
