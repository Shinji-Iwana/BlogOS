<?php

namespace App\Enums;

/**
 * AIが作った記事の管理情報の案の状態（article_management_suggestions.status）。D-27。
 */
enum SuggestionStatus: string
{
    // 人の確認待ち
    case Pending = 'pending';

    // 人が確認して登録した（直してから登録した場合を含む）
    case Accepted = 'accepted';

    // 人が採用しなかった
    case Rejected = 'rejected';

    // 同じ記事の新しい案を作ったため、確認しなくなった
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => '確認待ち',
            self::Accepted   => '登録済み',
            self::Rejected   => '不採用',
            self::Superseded => '新しい案に置き換え',
        };
    }
}
