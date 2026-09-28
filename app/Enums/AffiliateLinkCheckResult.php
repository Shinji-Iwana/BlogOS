<?php

namespace App\Enums;

/**
 * プログラムのリンクの定期確認の結果（affiliate_programs.check_result）。D-33-09。
 */
enum AffiliateLinkCheckResult: string
{
    case Ok = 'ok';
    // 行き先が「見つかりません」のページなど。提携が終わっている可能性が高い
    case Suspect = 'suspect';
    // 通信の失敗などで、確認できなかった
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Ok      => '問題なし',
            self::Suspect => '提携終了の疑い',
            self::Error   => '確認できなかった',
        };
    }
}
