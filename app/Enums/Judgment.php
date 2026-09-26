<?php

namespace App\Enums;

/**
 * 評価項目・必須条件の判定（article_evaluation_details.judgment）。resources/quality/common/scoring.md。
 */
enum Judgment: string
{
    // ○ 満たしている（配点どおり）
    case Good = 'good';

    // △ 一部不足（配点の50%。切り上げない。D-06-03）
    case Partial = 'partial';

    // × 満たしていない（0点）
    case Bad = 'bad';

    // 要人間確認（AIでは判定できない項目。点数に含めない。D-07-03）
    case NeedsHuman = 'needs_human';

    public function label(): string
    {
        return match ($this) {
            self::Good       => '○',
            self::Partial    => '△',
            self::Bad        => '×',
            self::NeedsHuman => '要人間確認',
        };
    }

    /**
     * 画面・AIの出力の記号から変換する（○ △ × と、英語の表記）
     */
    public static function fromSymbol(?string $value): ?self
    {
        $value = trim((string) $value);

        return match (true) {
            in_array($value, ['○', '◯', 'o', 'O', 'good'], true)                  => self::Good,
            in_array($value, ['△', 'partial'], true)                               => self::Partial,
            in_array($value, ['×', 'x', 'X', 'bad'], true)                          => self::Bad,
            in_array($value, ['要人間確認', 'needs_human', '人が確認'], true)      => self::NeedsHuman,
            default                                                                  => null,
        };
    }
}
