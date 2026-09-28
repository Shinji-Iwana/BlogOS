<?php

namespace App\Enums;

/**
 * 画像の状態（images.status）。D-32。
 */
enum ImageStatus: string
{
    // 案（AI が作った・アップロードした直後）。人が確認するまで記事で使わない
    case Draft = 'draft';

    // 人が確認した（ファイル・alt・ファイル名がそろっている）
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '案',
            self::Ready => '確認済み',
        };
    }
}
