<?php

namespace App\Enums;

/**
 * キーワードの種類（article_keywords.keyword_type）。
 */
enum KeywordType: string
{
    case Main = 'main';
    case Sub = 'sub';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'メイン',
            self::Sub  => 'サブ',
        };
    }
}
