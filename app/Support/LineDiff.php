<?php

namespace App\Support;

/**
 * 2つの文章の行単位の差分（反映の確認画面・競合の解消の画面で使う）。
 *
 * 最長共通部分列で求める。行数が多すぎる場合は計算しない（null を返す）。
 */
class LineDiff
{
    /**
     * 計算する行数の上限（両方の行数の積）
     */
    protected const MAX_CELLS = 1_000_000;

    /**
     * @return array<int, array{type: string, line: string}>|null type は same / removed / added
     */
    public static function compute(?string $old, ?string $new): ?array
    {
        $a = self::lines($old);
        $b = self::lines($new);

        // 前後の一致する行は、表の計算から外す
        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix
            && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $midB = array_slice($b, $prefix, count($b) - $prefix - $suffix);

        if (count($midA) * count($midB) > self::MAX_CELLS) {
            return null;
        }

        $result = [];
        foreach (array_slice($a, 0, $prefix) as $line) {
            $result[] = ['type' => 'same', 'line' => $line];
        }
        array_push($result, ...self::lcsDiff($midA, $midB));
        foreach (array_slice($a, count($a) - $suffix) as $line) {
            $result[] = ['type' => 'same', 'line' => $line];
        }

        return $result;
    }

    /**
     * 変更のない行を、変更の前後 $context 行だけ残して省略する
     *
     * @param array<int, array{type: string, line: string}> $diff
     * @return array<int, array{type: string, line: string}> 省略した箇所は type = skip
     */
    public static function compact(array $diff, int $context = 3): array
    {
        $keep = [];
        foreach ($diff as $i => $row) {
            if ($row['type'] !== 'same') {
                for ($j = max(0, $i - $context); $j <= min(count($diff) - 1, $i + $context); $j++) {
                    $keep[$j] = true;
                }
            }
        }

        $result = [];
        $skipped = 0;
        foreach ($diff as $i => $row) {
            if (isset($keep[$i])) {
                if ($skipped > 0) {
                    $result[] = ['type' => 'skip', 'line' => "（変更のない {$skipped} 行）"];
                    $skipped = 0;
                }
                $result[] = $row;
            } else {
                $skipped++;
            }
        }
        if ($skipped > 0) {
            $result[] = ['type' => 'skip', 'line' => "（変更のない {$skipped} 行）"];
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    protected static function lines(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        return preg_split('/\r\n|\r|\n/', $text);
    }

    /**
     * @param array<int, string> $a
     * @param array<int, string> $b
     * @return array<int, array{type: string, line: string}>
     */
    protected static function lcsDiff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // $length[$i][$j]：a[i..] と b[j..] の最長共通部分列の長さ
        $length = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $length[$i][$j] = $a[$i] === $b[$j]
                    ? $length[$i + 1][$j + 1] + 1
                    : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }

        $result = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $result[] = ['type' => 'same', 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($length[$i + 1][$j] >= $length[$i][$j + 1]) {
                $result[] = ['type' => 'removed', 'line' => $a[$i++]];
            } else {
                $result[] = ['type' => 'added', 'line' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $result[] = ['type' => 'removed', 'line' => $a[$i++]];
        }
        while ($j < $m) {
            $result[] = ['type' => 'added', 'line' => $b[$j++]];
        }

        return $result;
    }
}
