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

    /**
     * 列の名前つきの値を、画面に出す形にする（D-72-04）。変更の記録の変更前・変更後や、取り込んだデータの列の値に使う。
     *
     * 名前が _at・_gmt で終わる日時（UTC で保存している）は、日本時間にする。それ以外（WordPress の date・modified は、
     * WordPress のサイトのタイムゾーンの時刻のまま保存している）は、そのまま返す
     */
    public static function value(string $field, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return self::format($value);
        }
        if (is_string($value) && preg_match('/(_at|_gmt)$/', $field) && self::isDateTime($value)) {
            return self::format($value);
        }

        return $value;
    }

    /**
     * WordPress API の応答の本文の日時を、日本時間にする（WordPress API情報の画面。D-72-04）。直した値には「（日本時間）」を付ける。
     *
     * - date_gmt などの _gmt の値（UTC）は、日本時間にする
     * - date・modified など、同じ名前の _gmt がある値は、その _gmt から日本時間にする（サイトのタイムゾーンによらず、そろえる）
     * - そのほか、タイムゾーンが付いた日時（例：2026-10-07T04:30:00+00:00）も、日本時間にする
     */
    public static function apiBody(mixed $body): mixed
    {
        if (! is_array($body)) {
            return $body;
        }

        $original = $body;
        foreach ($original as $key => $value) {
            if (is_array($value)) {
                $body[$key] = self::apiBody($value);

                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $gmt = is_string($key) ? ($original[$key . '_gmt'] ?? null) : null;
            if (is_string($key) && str_ends_with($key, '_gmt') && self::isDateTime($value)) {
                $body[$key] = self::japan($value . '+00:00');
            } elseif (is_string($gmt) && self::isDateTime($gmt)) {
                $body[$key] = self::japan($gmt . '+00:00');
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
                $body[$key] = self::japan($value);
            }
        }

        return $body;
    }

    /**
     * WordPress API の応答のヘッダーの日時（Date・Last-Modified・Expires。GMT）を、日本時間にする（D-72-04）
     *
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    public static function apiHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), ['date', 'last-modified', 'expires'], true)) {
                $headers[$name] = array_map(fn (string $value) => strtotime($value) !== false ? self::japan($value) : $value, $values);
            }
        }

        return $headers;
    }

    protected static function isDateTime(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value);
    }

    protected static function japan(string $value): string
    {
        return self::format($value) . '（日本時間）';
    }
}
