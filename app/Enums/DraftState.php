<?php

namespace App\Enums;

/**
 * 編集案の状態（article_drafts.state）。BLOGOS_DATABASE.md 9-1。
 */
enum DraftState: string
{
    case Editing = 'editing';
    case Review = 'review';
    case Pushed = 'pushed';
    case Discarded = 'discarded';

    /**
     * 作業中の編集案（同期で差分を検出したら競合として扱う。D-01-04）
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Editing, self::Review];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Editing   => '作業中',
            self::Review    => '確認待ち',
            self::Pushed    => '反映済み',
            self::Discarded => '破棄',
        };
    }
}
