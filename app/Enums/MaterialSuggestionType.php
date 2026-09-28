<?php

namespace App\Enums;

/**
 * AIが作った教材の案の種類（material_suggestions.type）。D-30。
 */
enum MaterialSuggestionType: string
{
    // 登録済みの教材の情報（調査・定期チェックの結果）
    case Research = 'research';

    // 新しい教材の候補（カテゴリを指定した候補探し、新しい版）。人がアフィリエイトのリンクを付けて登録する
    case Candidate = 'candidate';

    public function label(): string
    {
        return match ($this) {
            self::Research  => '教材の情報',
            self::Candidate => '新しい教材の候補',
        };
    }
}
