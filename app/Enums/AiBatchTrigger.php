<?php

namespace App\Enums;

/**
 * まとめて実行のきっかけ（ai_batches.trigger）。D-25。
 */
enum AiBatchTrigger: string
{
    // 人が画面から実行した
    case Manual = 'manual';

    // 条件による自動の再評価（ブログのAIの設定で有効にした場合だけ）
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => '手動（まとめて実行）',
            self::Auto   => '自動の再評価',
        };
    }
}
