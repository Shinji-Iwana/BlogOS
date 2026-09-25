<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * 画面に表示する日時。DBのUTCの値を、表示用のタイムゾーン（config/blogos.php display_timezone）に変換する（D-19-03）。
 */
class DisplayTime
{
    public static function format(DateTimeInterface|string|null $value, string $format = 'Y-m-d H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)
            ->timezone(config('blogos.display_timezone', 'Asia/Tokyo'))
            ->format($format);
    }
}
