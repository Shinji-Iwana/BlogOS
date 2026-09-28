<?php

namespace App\Enums;

/**
 * 教材の種類（materials.kind）。品質基準 si-note/tech-content.md 5-2〜5-5。D-30・D-33。
 */
enum MaterialKind: string
{
    case Book = 'book';
    case Udemy = 'udemy';
    case School = 'school';
    // 資格試験のオンライン問題集など（D-33-08）
    case QuestionBank = 'question_bank';

    public function label(): string
    {
        return match ($this) {
            self::Book         => '書籍',
            self::Udemy        => 'Udemy',
            self::School       => 'スクール',
            self::QuestionBank => '問題集・オンライン教材',
        };
    }

    /**
     * 費用の目安（・学習期間）を載せる種類（価格が変わりにくく、読者の判断に必要なもの）
     */
    public function hasCost(): bool
    {
        return in_array($this, [self::School, self::QuestionBank], true);
    }
}
