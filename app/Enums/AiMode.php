<?php

namespace App\Enums;

/**
 * BlogOSのAI機能の実行モード（ai_generations.purpose）。resources/quality/common/ai-and-operation.md 5章。
 */
enum AiMode: string
{
    case SeoAnalysis = 'seo_analysis';
    case Structure = 'structure';
    case QualityDiagnosis = 'quality_diagnosis';
    case Revision = 'revision';
    case NewArticle = 'new_article';

    public function label(): string
    {
        return match ($this) {
            self::SeoAnalysis      => 'SEO分析',
            self::Structure        => '構成作成',
            self::QualityDiagnosis => '品質診断',
            self::Revision         => '記事改修',
            self::NewArticle       => '新規記事作成',
        };
    }
}
