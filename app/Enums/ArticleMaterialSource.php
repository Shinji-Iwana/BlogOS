<?php

namespace App\Enums;

/**
 * 記事で使っている教材の記録の元（article_materials.source）。D-30。
 */
enum ArticleMaterialSource: string
{
    // 記事の本文にある教材のリンクから検出した
    case Detected = 'detected';

    // 人が登録した
    case Human = 'human';

    public function label(): string
    {
        return match ($this) {
            self::Detected => '本文から検出',
            self::Human    => '人が登録',
        };
    }
}
