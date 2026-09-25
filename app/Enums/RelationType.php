<?php

namespace App\Enums;

/**
 * 記事同士の関係の種類（article_relations.relation_type）。D-15-10。各ブログでの意味は品質基準のブログ別の定義で定める。
 */
enum RelationType: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Previous = 'previous';
    case Next = 'next';
    case Related = 'related';
    case Advanced = 'advanced';
    case Comparison = 'comparison';
    case Troubleshooting = 'troubleshooting';
    case Monetization = 'monetization';

    public function label(): string
    {
        return match ($this) {
            self::Parent          => '上位の記事',
            self::Child           => '下位の記事',
            self::Previous        => '前の記事',
            self::Next            => '次の記事',
            self::Related         => '関連記事',
            self::Advanced        => '発展・応用',
            self::Comparison      => '比較',
            self::Troubleshooting => 'エラー・問題解決',
            self::Monetization    => '収益',
        };
    }
}
