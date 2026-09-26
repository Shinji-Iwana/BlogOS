<?php

namespace App\Enums;

/**
 * まとめて実行の対象の選び方（ai_batches.target）。D-25。対象は公開中の投稿・固定ページ。
 */
enum AiBatchTarget: string
{
    // 公開中の全ての記事
    case All = 'all';

    // まだ評価していない記事
    case Unevaluated = 'unevaluated';

    // 再評価の条件（D-25-04）に当てはまる記事
    case NeedsReevaluation = 'needs_reevaluation';

    // 最新の評価の点数が基準に満たない記事
    case BelowScore = 'below_score';

    // 自動の再評価（条件と理由は ai_batch_items.reason）
    case Auto = 'auto';

    // 品質診断のまとめて実行の結果、基準に満たなかった記事（続けて行う記事改修。D-26）
    case AfterDiagnosis = 'after_diagnosis';

    // 管理情報（記事種類とメインキーワード）が未登録で、確認待ちの案もない記事（D-27）
    case Unmanaged = 'unmanaged';

    public function label(): string
    {
        return match ($this) {
            self::All               => '公開中の全ての記事',
            self::Unevaluated       => 'まだ評価していない記事',
            self::NeedsReevaluation => '再評価の条件に当てはまる記事',
            self::BelowScore        => '最新の評価の点数が基準に満たない記事',
            self::Auto              => '自動の再評価の条件に当てはまる記事',
            self::AfterDiagnosis    => '品質診断の結果、基準に満たなかった記事',
            self::Unmanaged         => '管理情報（記事種類・メインキーワード）が未登録の記事',
        };
    }

    /**
     * 実行モードごとに、画面で選べる対象
     *
     * @return list<self>
     */
    public static function selectableFor(AiMode $mode): array
    {
        return match ($mode) {
            AiMode::QualityDiagnosis => [self::All, self::Unevaluated, self::NeedsReevaluation, self::BelowScore],
            // 改修は、評価の結果をもとにする（評価していない記事は対象にしない）
            AiMode::Revision         => [self::BelowScore],
            AiMode::ManagementSuggestion => [self::Unmanaged, self::All],
            default                  => [],
        };
    }
}
