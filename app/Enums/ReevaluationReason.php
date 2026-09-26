<?php

namespace App\Enums;

/**
 * 再評価の理由（ai_batch_items.reason）。D-25-04。並び順は、自動の再評価で優先する順。
 */
enum ReevaluationReason: string
{
    // まだ評価していない（新しく公開した記事を含む）
    case Unevaluated = 'unevaluated';

    // 1. 評価した後に、記事が更新された
    case Changed = 'changed';

    // 2. 品質基準・AI実行テンプレートのバージョンが変わった
    case Version = 'version';

    // 3. この記事へのリンクの数が変わった
    case Links = 'links';

    // 4. アクセスが落ちた
    case Traffic = 'traffic';

    // 5. 前回の評価から一定の日数が過ぎた
    case Periodic = 'periodic';

    public function label(): string
    {
        return match ($this) {
            self::Unevaluated => '未評価',
            self::Changed     => '記事の更新',
            self::Version     => '品質基準・テンプレートの更新',
            self::Links       => 'この記事へのリンクの増減',
            self::Traffic     => 'アクセスの減少',
            self::Periodic    => '定期的な見直し',
        };
    }

    public function priority(): int
    {
        return array_search($this, self::cases(), true);
    }
}
