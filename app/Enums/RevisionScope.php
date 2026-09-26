<?php

namespace App\Enums;

/**
 * 改修範囲（D-06-01、D-14-08）。指定がなければ軽微な改善とする。
 */
enum RevisionScope: string
{
    case Minor = 'minor';
    case Restructure = 'restructure';
    case Full = 'full';

    /**
     * 改修前の点数から改修範囲を決める（人が「点数で自動判別」を選んだ場合。D-27-02）。点数がなければ軽微な改善
     */
    public static function byScore(?float $score): self
    {
        $thresholds = (array) config('blogos.ai.revision_scope_by_score');

        return match (true) {
            $score === null || $score >= (float) $thresholds['minor'] => self::Minor,
            $score >= (float) $thresholds['restructure']              => self::Restructure,
            default                                                    => self::Full,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Minor       => '軽微な改善',
            self::Restructure => '構成の見直し',
            self::Full        => '全面改修',
        };
    }
}
