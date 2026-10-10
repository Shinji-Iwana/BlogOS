<?php

namespace App\Support;

/**
 * 品質基準の「判定の基準（○／△）」の文を、判定ごとに分ける（D-79-02。品質評価の詳細の画面）。
 *
 * 品質基準のファイルでは「○ …… ／ △ ……」の形で1つの文に書いている。○・△・× の前の「／」で区切り、
 * [判定の記号, 補足] の並びにする。記号で始まらない文（区切れないもの）は、記号なしの1行にする。
 */
class QualityCriteria
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function split(?string $criteria): array
    {
        if ($criteria === null || trim($criteria) === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\s*／\s*(?=[○△×])/u', trim($criteria)) as $part) {
            $rows[] = preg_match('/^([○△×])\s*(.*)$/us', $part, $match) ? [$match[1], trim($match[2])] : ['', trim($part)];
        }

        return $rows;
    }
}
