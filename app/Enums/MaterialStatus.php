<?php

namespace App\Enums;

/**
 * 教材の状態（materials.status）。D-30。
 */
enum MaterialStatus: string
{
    // 記事で使える
    case Active = 'active';

    // 使わない（販売の終了・リンクの停止など）。記事で使っている場合は、見直しの対象になる
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active   => '使う',
            self::Inactive => '使わない',
        };
    }
}
