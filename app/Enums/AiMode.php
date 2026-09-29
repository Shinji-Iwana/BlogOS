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

    // 記事の管理情報（記事種類・キーワード・検索意図）の案。人が確認して登録する（D-27）
    case ManagementSuggestion = 'management_suggestion';

    // 教材（D-30）：登録済みの教材の情報の調査（定期チェックを兼ねる）、カテゴリを指定した候補探し、記事の教材の見直し
    case MaterialResearch = 'material_research';
    case MaterialDiscovery = 'material_discovery';
    case MaterialReview = 'material_review';

    // 画像（D-32）：図の作成（SVG か、イラストの指示文を作る）、画像の生成（画像モデル。API実行だけ）
    case ImageDesign = 'image_design';
    case ImageGeneration = 'image_generation';

    // 記事の企画（D-40）：子カテゴリのまだ記事にしていない内容・親カテゴリの足りない子カテゴリの案。人が確認して採用する
    case TopicPlanning = 'topic_planning';

    public function label(): string
    {
        return match ($this) {
            self::ImageDesign     => '図の作成',
            self::ImageGeneration => '画像の生成',
            self::TopicPlanning   => '記事の企画',
            self::ManagementSuggestion => '管理情報の案',
            self::SeoAnalysis      => 'SEO分析',
            self::Structure        => '構成作成',
            self::QualityDiagnosis => '品質診断',
            self::Revision         => '記事改修',
            self::NewArticle       => '新規記事作成',
            self::MaterialResearch  => '教材の調査',
            self::MaterialDiscovery => '教材の候補探し',
            self::MaterialReview    => '記事の教材の見直し',
        };
    }

    /**
     * 教材の画面から実行するモード（AIの実行の画面では選ばない）
     */
    public function isMaterialMode(): bool
    {
        return in_array($this, [self::MaterialResearch, self::MaterialDiscovery, self::MaterialReview], true);
    }

    /**
     * 画像の画面から実行するモード（AIの実行の画面では選ばない）
     */
    public function isImageMode(): bool
    {
        return in_array($this, [self::ImageDesign, self::ImageGeneration], true);
    }

    /**
     * 専用の画面から実行するモード（AIの実行の画面では選ばない）
     */
    public function hasOwnScreen(): bool
    {
        return $this->isMaterialMode() || $this->isImageMode() || $this === self::TopicPlanning;
    }

    /**
     * Web検索を使えるモード（API実行）
     */
    public function canUseWebSearch(): bool
    {
        return in_array($this, [self::MaterialResearch, self::MaterialDiscovery, self::TopicPlanning], true);
    }
}
