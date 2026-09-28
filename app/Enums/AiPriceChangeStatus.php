<?php

namespace App\Enums;

/**
 * 料金の変更の状態（ai_price_changes.status）。D-31-03。
 */
enum AiPriceChangeStatus: string
{
    // 料金表に反映した（値上がりは自動、値下がりは人が確認して）
    case Applied = 'applied';

    // 値下がり：人の確認待ち
    case Pending = 'pending';

    // 人が反映しなかった
    case Rejected = 'rejected';

    // 公式のページの値がさらに変わった・元に戻ったため、確認しなくなった
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Applied    => '反映済み',
            self::Pending    => '確認待ち',
            self::Rejected   => '反映しない',
            self::Superseded => '新しい値に置き換え',
        };
    }
}
