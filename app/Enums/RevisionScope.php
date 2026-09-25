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

    public function label(): string
    {
        return match ($this) {
            self::Minor       => '軽微な改善',
            self::Restructure => '構成の見直し',
            self::Full        => '全面改修',
        };
    }
}
