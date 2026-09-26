<?php

namespace App\Services\Ai;

use App\Enums\AiMode;
use App\Enums\KeywordType;
use App\Enums\RevisionScope;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\ArticleRepository;
use App\Repositories\GoogleMetricRepository;
use App\Services\Quality\QualityStandard;
use App\Services\Quality\QualityStandardLoader;
use App\Support\QualityProfiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * AI実行テンプレートと品質基準・記事の情報から、AIへの指示文を作る（ARCHITECTURE 18-3・18-4）。
 *
 * 認証情報と、保存しないと決めた個人情報（D-05-04）は含めない。
 */
class PromptBuilder
{
    /**
     * 改修範囲の説明（resources/quality/common/ai-and-operation.md 3章、D-14-08）
     */
    protected const SCOPE_RULES = [
        'minor'       => '軽微な改善（既定）：不足している項目の追加と、表現の改善。見出しの構成は維持する。既存の内容を不要に削除しない。',
        'restructure' => '構成の見直し：見出しの追加・削除・順序の変更、説明の順序の変更をしてよい。主要な内容は維持する。',
        'full'        => '全面改修（人が指定）：構成・文章・図解・見出しを全面的に見直してよい。',
    ];

    public function __construct(
        protected QualityStandardLoader $loader,
        protected ArticleRepository $articles,
        protected ArticleManagementRepository $managements,
        protected ArticleEvaluationRepository $evaluations,
        protected GoogleMetricRepository $metrics,
    ) {
    }

    /**
     * @param array<string, string|null> $parameters 人が画面で入力した情報（ラベル => 値）
     * @return array{prompt: string, template: AiTemplate, standard: QualityStandard}
     */
    public function build(AiMode $mode, Blog $blog, Post|Page|null $article, ?ArticleDraft $draft, array $parameters, ?RevisionScope $scope): array
    {
        $template = AiTemplate::load($mode);
        $standard = $this->loader->load($blog->quality_profile);
        $articleType = $article ? $this->managements->findFor($article)?->article_type : ($parameters['記事種類の値'] ?? null);

        $values = [
            'blog_name'        => $blog->display_name,
            'blog_home'        => $blog->home,
            'quality_versions' => "共通基準 {$standard->commonVersion}" . ($standard->profile ? "、{$standard->profile} {$standard->profileVersion}" : ''),
            'quality_files'    => $this->qualityFiles($template, $standard),
            'required_table'   => $this->requiredTable($standard),
            'scoring_table'    => $this->scoringTable($standard, $articleType),
            'article_info'     => $this->articleInfo($blog, $article, $draft),
            'article_content'  => (string) ($draft?->content_raw ?? $article?->content_raw ?? ''),
            'revision_scope'   => self::SCOPE_RULES[($scope ?? RevisionScope::Minor)->value],
            'shortfalls'       => $this->shortfalls($article, $draft, $standard),
            'parameters'       => $this->parameters($parameters),
            'article_list'     => $this->articleList($blog),
        ];

        $prompt = preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => $values[$m[1]] ?? $m[0], $template->body);

        return ['prompt' => $prompt, 'template' => $template, 'standard' => $standard];
    }

    protected function qualityFiles(AiTemplate $template, QualityStandard $standard): string
    {
        $sections = [];

        foreach ($template->qualityFiles as $file) {
            if (str_contains($file, '{profile}')) {
                if ($standard->profile === null) {
                    continue;
                }
                $file = str_replace('{profile}', "blogs/{$standard->profile}", $file);
            }

            $path = resource_path("quality/{$file}");
            if (File::exists($path)) {
                $sections[] = "## resources/quality/{$file}\n\n" . trim(str_replace("\r\n", "\n", File::get($path)));
            }
        }

        return implode("\n\n", $sections);
    }

    protected function requiredTable(QualityStandard $standard): string
    {
        $lines = ['| キー | 条件 | 判定者 |', '| --- | --- | --- |'];
        foreach ($standard->required as $key => $condition) {
            $lines[] = "| {$key} | {$condition['label']} | " . ($condition['ai'] ? 'AI・人' : '人') . ' |';
        }

        return implode("\n", $lines);
    }

    protected function scoringTable(QualityStandard $standard, ?string $articleType): string
    {
        $lines = ['| キー | 分類 | 評価項目 | 配点 | 判定者 |', '| --- | --- | --- | --- | --- |'];
        foreach ($standard->applicableItems($articleType) as $key => $item) {
            $lines[] = "| {$key} | {$item['category']} | {$item['label']} | {$item['points']} | " . ($item['ai'] ? 'AI・人' : '人') . ' |';
        }

        $excluded = $standard->excludedItems($articleType);
        if ($excluded !== []) {
            $lines[] = '';
            $lines[] = '対象外の項目（出力に含めない）：' . implode('、', $excluded);
        }

        return implode("\n", $lines);
    }

    protected function articleInfo(Blog $blog, Post|Page|null $article, ?ArticleDraft $draft): string
    {
        if ($article === null && $draft === null) {
            return '（新しい記事のため、ありません）';
        }

        $lines = [];
        $lines[] = '- 種類：' . ($article instanceof Page || $draft?->target_type?->value === 'page' ? '固定ページ' : '投稿') . ($draft ? '（BlogOSの編集案）' : '');
        $lines[] = '- タイトル：' . ($draft?->title_raw ?? $article?->title_raw ?? '');
        if ($article) {
            $lines[] = "- URL：{$article->link}";
            $lines[] = "- ステータス：{$article->status}";
        }

        // メタディスクリプション（AIOSEO。D-23-01）。編集案では、編集案に設定した値
        $description = $draft !== null ? $draft->meta_description : $article?->meta_description_raw;
        if (filled($description)) {
            $lines[] = "- メタディスクリプション：{$description}（" . mb_strlen($description) . '文字）';
        } elseif ($article?->meta_description_rendered !== null) {
            $lines[] = "- メタディスクリプション：未設定（SEOプラグインが本文の冒頭から自動で作った説明：{$article->meta_description_rendered}）";
        } else {
            $lines[] = '- メタディスクリプション：未設定';
        }

        if ($article) {
            $management = $this->managements->findFor($article);
            $types = QualityProfiles::articleTypes($blog->quality_profile);
            if ($management) {
                $lines[] = '- 記事種類：' . ($types['types'][$management->article_type] ?? $management->article_type ?? '未設定')
                    . ($management->article_subtype ? '（' . ($types['subtypes'][$management->article_subtype] ?? $management->article_subtype) . '）' : '');
                $lines[] = '- 主の検索意図：' . ($management->main_search_intent ?: '未設定');
                if ($management->sub_search_intents) {
                    $lines[] = '- 副の検索意図：' . implode('／', $management->sub_search_intents);
                }
            }

            $keywords = $this->managements->keywordsFor($article);
            if ($keywords->isNotEmpty()) {
                $lines[] = '- メインキーワード：' . ($keywords->firstWhere('keyword_type', KeywordType::Main)?->keyword ?? 'なし');
                $lines[] = '- サブキーワード：' . ($keywords->where('keyword_type', KeywordType::Sub)->pluck('keyword')->implode('、') ?: 'なし');
            }

            $inbound = $this->articles->inboundLinks($article);
            $lines[] = "- この記事へのリンク：{$inbound->count()}件"
                . ($inbound->isNotEmpty() ? '（' . $inbound->take(10)->map(fn ($l) => ($l->sourcePost ?? $l->sourcePage)?->title_raw)->filter()->implode('、') . '）' : '');
            $lines[] = '- この記事からの内部リンク：' . $this->articles->outboundLinks($article)->count() . '件';

            // Search Console の検索クエリ（直近90日）
            $to = Carbon::parse(Carbon::now(config('blogos.display_timezone'))->subDay()->toDateString());
            $queries = $this->metrics->articleSummary($article instanceof Post ? 'post_id' : 'page_id', $article->id, $to->copy()->subDays(89), $to)['queries']->take(20);
            if ($queries->isNotEmpty()) {
                $lines[] = '- Search Console の検索クエリ（直近90日。クエリ：クリック数／表示回数／平均掲載順位）：';
                foreach ($queries as $query) {
                    $lines[] = "  - {$query->query}：{$query->clicks}／{$query->impressions}／" . round((float) $query->position, 1);
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function shortfalls(Post|Page|null $article, ?ArticleDraft $draft, QualityStandard $standard): string
    {
        $evaluation = $this->evaluations->latestFor($article, $draft) ?? ($article ? $this->evaluations->latestFor($article, null) : null);

        if ($evaluation === null) {
            return '（評価がありません。品質基準の全体を見て改善してください）';
        }

        $lines = ["（{$evaluation->created_at?->format('Y-m-d')} の" . $evaluation->evaluator_type->label() . 'の評価：' . ($evaluation->score ?? '-') . '点）'];
        foreach ($evaluation->details as $detail) {
            if ($detail->judgment->value === 'good') {
                continue;
            }
            $label = $standard->items[$detail->item_key]['label'] ?? $standard->required[$detail->item_key]['label'] ?? '';
            $lines[] = "- {$detail->item_key}（{$label}）：{$detail->judgment->label()}" . ($detail->comment ? " — {$detail->comment}" : '');
        }

        return count($lines) === 1 ? $lines[0] . "\n- 不足点はありません" : implode("\n", $lines);
    }

    protected function parameters(array $parameters): string
    {
        $lines = [];
        foreach ($parameters as $label => $value) {
            if ($label === '記事種類の値' || blank($value)) {
                continue;
            }
            $lines[] = "- {$label}：" . str_replace("\n", "\n  ", trim((string) $value));
        }

        return $lines === [] ? '（なし）' : implode("\n", $lines);
    }

    protected function articleList(Blog $blog): string
    {
        $lines = $this->articles->publishedList($blog->id)->map(fn ($a) => "- {$a->title_raw}：{$a->link}")->all();

        return $lines === [] ? '（なし）' : implode("\n", $lines);
    }
}
