<?php

namespace App\Enums;

/**
 * 編集案の作成元（article_drafts.origin）。D-07-06。
 */
enum DraftOrigin: string
{
    case Human = 'human';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Human => '人',
            self::Ai    => 'AI',
        };
    }
}
