<?php

namespace App\Enums;

/**
 * 評価した主体（article_evaluations.evaluator_type）。BLOGOS_DATABASE.md 9-5。
 */
enum EvaluatorType: string
{
    case Ai = 'ai';
    case Human = 'human';

    public function label(): string
    {
        return match ($this) {
            self::Ai    => 'AI',
            self::Human => '人',
        };
    }
}
