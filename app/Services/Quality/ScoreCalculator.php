<?php

namespace App\Services\Quality;

use App\Enums\Judgment;

/**
 * 点数と判定の計算（resources/quality/common/scoring.md 4・5章、D-06-02、D-06-03、D-14-06、D-15-01）。
 *
 * * ○ は配点どおり、△ は配点の50%（切り上げない）、× は0点
 * * 対象外の項目と、要人間確認の項目は、配点から除外して100点に換算する
 * * 点数は小数第2位を切り捨て、小数第1位まで
 */
class ScoreCalculator
{
    public const PASS_SCORE = 95.0;

    /**
     * @param array<string, Judgment> $judgments キー => 判定（必須条件・採点項目の両方）
     * @return array{
     *     score: float|null,
     *     earned: float,
     *     max: int,
     *     required_passed: bool|null,
     *     unjudged: array<int, string>,
     *     needs_human: array<int, string>,
     *     excluded: array<int, string>,
     *     passed: bool
     * }
     */
    public function calculate(QualityStandard $standard, ?string $articleType, array $judgments): array
    {
        $earned = 0.0;
        $max = 0;
        $unjudged = [];
        $needsHuman = [];

        foreach ($standard->applicableItems($articleType) as $key => $item) {
            $judgment = $judgments[$key] ?? null;

            if ($judgment === null) {
                $unjudged[] = $key;

                continue;
            }
            if ($judgment === Judgment::NeedsHuman) {
                $needsHuman[] = $key;

                continue;
            }

            $max += $item['points'];
            $earned += match ($judgment) {
                Judgment::Good    => $item['points'],
                Judgment::Partial => $item['points'] / 2,
                default           => 0,
            };
        }

        // 必須条件：×が1つでもあれば不合格。判定していない・要人間確認のものがあれば未確定（null）
        $requiredPassed = true;
        foreach (array_keys($standard->required) as $key) {
            $judgment = $judgments[$key] ?? null;

            if ($judgment === Judgment::Bad) {
                $requiredPassed = false;

                break;
            }
            if ($judgment !== Judgment::Good) {
                $requiredPassed = null;
                if ($judgment === null) {
                    $unjudged[] = $key;
                } else {
                    $needsHuman[] = $key;
                }
            }
        }

        $score = $max > 0 ? floor($earned / $max * 1000) / 10 : null;

        return [
            'score'           => $score,
            'earned'          => $earned,
            'max'             => $max,
            'required_passed' => $requiredPassed,
            'unjudged'        => $unjudged,
            'needs_human'     => $needsHuman,
            'excluded'        => $standard->excludedItems($articleType),
            'passed'          => $requiredPassed === true && $unjudged === [] && $needsHuman === [] && $score !== null && $score >= self::PASS_SCORE,
        ];
    }

    /**
     * 点数の判定（scoring.md 5章）
     */
    public static function verdict(?float $score, ?bool $requiredPassed): string
    {
        return match (true) {
            $requiredPassed === false => '公開不可（必須条件を満たしていない）',
            $score === null           => '未評価',
            $score >= 95.0            => $requiredPassed === true ? '公開可' : '点数は基準以上（必須条件が未確定）',
            $score >= 90.0            => '公開不可（改善して再評価）',
            default                   => '公開不可（大幅な改善が必要）',
        };
    }
}
