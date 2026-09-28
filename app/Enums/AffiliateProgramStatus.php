<?php

namespace App\Enums;

/**
 * アフィリエイトのプログラム（提携先の広告）の状態（affiliate_programs.status）。D-33-08。
 */
enum AffiliateProgramStatus: string
{
    case Active = 'active';
    case Applying = 'applying';
    case Rejected = 'rejected';
    case Ended = 'ended';
    // 記事のリンクから自動で登録し、人がまだ状態を確かめていない
    case Unconfirmed = 'unconfirmed';

    public function label(): string
    {
        return match ($this) {
            self::Active      => '提携中',
            self::Applying    => '申請中',
            self::Rejected    => '否認',
            self::Ended       => '提携終了',
            self::Unconfirmed => '未確認',
        };
    }

    /**
     * 記事で紹介に使えるか。未確認は、今の記事を止めないよう使える扱いにし、画面で確認を促す
     */
    public function usable(): bool
    {
        return in_array($this, [self::Active, self::Unconfirmed], true);
    }
}
