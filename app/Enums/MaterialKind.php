<?php

namespace App\Enums;

/**
 * 教材の種類（materials.kind）。品質基準 si-note/tech-content.md 5-2〜5-4。D-30。
 */
enum MaterialKind: string
{
    case Book = 'book';
    case Udemy = 'udemy';
    case School = 'school';

    public function label(): string
    {
        return match ($this) {
            self::Book   => '書籍',
            self::Udemy  => 'Udemy',
            self::School => 'スクール',
        };
    }
}
