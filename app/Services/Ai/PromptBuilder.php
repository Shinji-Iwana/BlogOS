<?php

namespace App\Services\Ai;

use App\Enums\AiMode;
use App\Enums\KeywordType;
use App\Enums\RevisionScope;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\ArticleRepository;
use App\Repositories\GoogleMetricRepository;
use App\Models\Image;
use App\Services\Articles\InternalLinkChecker;
use App\Services\Images\ImagePromptValues;
use App\Services\Materials\MaterialMatcher;
use App\Services\Materials\MaterialPromptValues;
use App\Services\Topics\TopicPlanningPromptValues;
use App\Services\Quality\QualityStandard;
use App\Services\Quality\QualityStandardLoader;
use App\Services\Quality\RevisionFindingService;
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

    /**
     * 人が画面で入力した情報のうち、BlogOSが使うだけで、指示文の「人が提供した情報」に入れないもの
     */
    public const HIDDEN_PARAMETERS = ['記事種類の値', 'カテゴリの値', '教材の種類の値', '形式の値', '企画の単位の値', '記事の企画の値', '立ち上げの子の値', '細分類の値'];

    public function __construct(
        protected QualityStandardLoader $loader,
        protected ArticleRepository $articles,
        protected ArticleManagementRepository $managements,
        protected ArticleEvaluationRepository $evaluations,
        protected GoogleMetricRepository $metrics,
        protected MaterialPromptValues $materialValues,
        protected ImagePromptValues $imageValues,
        protected MaterialMatcher $materialMatcher,
        protected TopicPlanningPromptValues $topicValues,
        protected InternalLinkChecker $links,
        protected RevisionFindingService $findings,
    ) {
    }

    /**
     * @param array<string, string|null> $parameters 人が画面で入力した情報（ラベル => 値）
     * @param Material|null $material 教材の調査の対象（D-30）
     * @param bool $webSearch Web検索を使うAPI実行か（教材の調査・候補探し）
     * @return array{prompt: string, template: AiTemplate, standard: QualityStandard}
     */
    public function build(AiMode $mode, Blog $blog, Post|Page|null $article, ?ArticleDraft $draft, array $parameters, ?RevisionScope $scope, ?Material $material = null, bool $webSearch = false, ?Image $image = null): array
    {
        $template = AiTemplate::load($mode);
        $standard = $this->loader->load($blog->quality_profile);
        $management = $article ? $this->managements->findFor($article) : null;
        $articleType = $management?->article_type ?? ($parameters['記事種類の値'] ?? null);
        // 集客記事の細分類（記事の型の採点項目を決める。D-47）
        $articleSubtype = $management?->article_subtype ?? ($parameters['細分類の値'] ?? null);

        $values = [
            'blog_name'        => $blog->display_name,
            'blog_home'        => $blog->home,
            'quality_versions' => "共通基準 {$standard->commonVersion}" . ($standard->profile ? "、{$standard->profile} {$standard->profileVersion}" : ''),
            'quality_files'    => $this->qualityFiles($template, $standard),
            'required_table'   => $this->requiredTable($standard),
            'scoring_table'    => $this->scoringTable($standard, $articleType, $articleSubtype),
            'article_info'     => $this->articleInfo($blog, $article, $draft),
            'article_content'  => (string) ($draft?->content_raw ?? $article?->content_raw ?? ''),
            'revision_scope'   => self::SCOPE_RULES[($scope ?? RevisionScope::Minor)->value],
            // 指摘（番号付き。記事改修で直すべきこと。D-47）
            'shortfalls'       => $this->findings->describe($article, $draft, $standard),
            // 編集案の品質診断で確かめる、前回の改修の指摘（D-47）
            'previous_findings' => $mode === AiMode::QualityDiagnosis ? $this->findings->checkList($draft) : '（なし）',
            // 編集案の品質診断：前回の判定（直していない所の判定が揺れないように）と、本文の画像の目印の中身（D-70）
            'previous_judgments' => $mode === AiMode::QualityDiagnosis && $draft !== null ? $this->findings->previousJudgments($article, $draft, $standard) : '（なし）',
            'image_details'    => $mode === AiMode::QualityDiagnosis && $draft !== null ? $this->imageDetails($blog, (string) $draft->content_raw) : '（本文に画像の目印はありません）',
            // 情報の時点の年月（html-rules.md 3-16。D-70）
            'today'            => now(config('blogos.display_timezone'))->format('Y年n月'),
            'parameters'       => $this->parameters($parameters),
            'article_list'     => $this->articleList($blog),
            // この記事の内部リンクの問題（D-42）
            'link_issues'      => $article !== null ? $this->links->describeFor($article) : '（なし）',
            'article_type_options' => $this->articleTypeOptions($blog),
        ];

        // 教材の調査・候補探し・見直し（D-30）
        if ($mode->isMaterialMode()) {
            $values = $this->materialValues->values($mode, $blog, $article, $material, $parameters, $webSearch) + $values;
        }

        // 記事改修・新規記事作成（D-34）：紹介してよい教材の候補と、この記事の画像
        if (in_array($mode, [AiMode::Revision, AiMode::NewArticle], true)) {
            $values['material_candidates'] = MaterialMatcher::describe($this->materialMatcher->candidates($blog, $article, [
                'category_ids' => ctype_digit((string) ($parameters['カテゴリの値'] ?? '')) ? [(int) $parameters['カテゴリの値']] : [],
                'keywords'     => array_values(array_filter(array_merge([$parameters['メインキーワード'] ?? null], preg_split('/\R/u', (string) ($parameters['サブキーワード'] ?? ''))))),
                'title'        => $parameters['メインキーワード'] ?? null,
                'article_type' => $parameters['記事種類の値'] ?? null,
            ]));
            $values['image_list'] = $this->imageList($blog, $draft, (string) $values['article_content']);
        }

        // 記事の企画（D-40）
        if ($mode === AiMode::TopicPlanning) {
            $values = $this->topicValues->values($blog, $parameters, $webSearch) + $values;
        }

        // 図の作成（D-32）
        if ($mode === AiMode::ImageDesign) {
            $values = $this->imageValues->values($image, $article, $parameters) + $values;
        }

        // 孤立記事をロードマップに載せる改修（D-70-06）：記事を載せることだけを行い、指摘は渡さない
        if ($mode === AiMode::Revision && filled($parameters[\App\Services\Articles\RoadmapLinkService::PARAMETER] ?? null)) {
            $values['revision_scope'] = 'ロードマップに記事を載せる（BlogOS が指定）：下の「人が提供した情報」の「' . \App\Services\Articles\RoadmapLinkService::PARAMETER . '」の記事を、内容に合うステップの記事の一覧（html-rules.md 3-15）に、目印（例：[[記事:24]]）で1つずつ加えてください。'
                . '学習の順番に合う位置に置き、合うステップがなければ、学習の順番に合う位置に新しいステップを加えてください。'
                . 'ほかの部分（ほかのステップの見出し・説明・載っている記事・タイトル・メタディスクリプション・抜粋）は変えないでください。情報の時点の段落の追加も、この改修では行わないでください。';
            $values['shortfalls'] = '（この改修では、ロードマップに記事を載せることだけを行います。品質の指摘は扱いません。「=== 指摘への対応 ===」は [] にしてください）';
        }

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

    /**
     * 採点の対象の項目と、判定の基準（品質基準 2.0.0。記事の型の項目を含む。D-47）
     */
    protected function scoringTable(QualityStandard $standard, ?string $articleType, ?string $articleSubtype = null): string
    {
        $form = $standard->formFor($articleType, $articleSubtype);
        $lines = [$form !== null
            ? "記事の型：{$form}（② 記事の型は、この型の項目で採点する。★ は必須で、× なら点数に関係なく公開不可）"
            : '記事の型：未登録（記事種類・細分類が決まらないため、② 記事の型の項目は採点しない）', ''];
        $lines[] = '| キー | 分類 | 評価項目 | 配点 | 判定者 | 判定の基準（○／△。どちらでもなければ ×） |';
        $lines[] = '| --- | --- | --- | --- | --- | --- |';
        foreach ($standard->applicableItems($articleType, $articleSubtype) as $key => $item) {
            $lines[] = "| {$key} | {$item['category']} | {$item['label']}" . (($item['required'] ?? false) ? '（★必須）' : '') . " | {$item['points']} | "
                . ($item['ai'] ? 'AI・人' : '人') . ' | ' . ($item['criteria'] ?? '') . ' |';
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
                if (filled($management->target_versions)) {
                    $lines[] = '- 対象のバージョン：' . $management->target_versions;
                }
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

            // Google のインデックスの登録状態（D-37）。登録されていない記事は、分類ごとの改修の方針を伝える
            $index = GoogleIndexStatus::where($article instanceof Post ? 'post_id' : 'page_id', $article->id)->first();
            if ($index?->category !== null) {
                $lines[] = '- Google のインデックス：' . $index->category->label() . ($index->last_crawl_at ? '（最後に Google が読んだ日：' . $index->last_crawl_at->format('Y-m-d') . '）' : '')
                    . ($index->category->advice() !== '' ? '。改修の方針：' . $index->category->advice() : '');
            }

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

    protected function parameters(array $parameters): string
    {
        $lines = [];
        foreach ($parameters as $label => $value) {
            if (in_array($label, self::HIDDEN_PARAMETERS, true) || blank($value)) {
                continue;
            }
            $lines[] = "- {$label}：" . str_replace("\n", "\n  ", trim((string) $value));
        }

        return $lines === [] ? '（なし）' : implode("\n", $lines);
    }

    /**
     * 管理情報の案で選べる記事種類・細分類（ブログ別の定義 article-types.md）
     */
    protected function articleTypeOptions(Blog $blog): string
    {
        $types = QualityProfiles::articleTypes($blog->quality_profile);
        if ($types['types'] === []) {
            return '（定義がありません。article_type・article_subtype は null にしてください）';
        }

        $lines = ['記事種類：'];
        foreach ($types['types'] as $value => $label) {
            $lines[] = "- {$value}：{$label}";
        }
        $lines[] = '細分類：';
        foreach ($types['subtypes'] as $value => $label) {
            $lines[] = "- {$value}：{$label}";
        }

        return implode("\n", $lines);
    }

    /**
     * 公開中の記事。本文でリンクするときは、先頭の目印（[[記事:WordPress の ID]]）を書く（D-34）
     */
    protected function articleList(Blog $blog): string
    {
        // どこからもリンクされていない・ロードマップに載っていない記事には印を付け、関連記事・次に読む記事で優先させる（D-42）
        $orphans = $this->links->orphanIds($blog);
        $lines = $this->articles->publishedList($blog->id)->map(fn ($a) => "- [[記事:{$a->wordpress_id}]] {$a->title_raw}：{$a->link}"
            . (isset($orphans[$a instanceof Post ? 'posts' : 'pages'][$a->id]) ? '（リンクが少ない記事）' : ''))->all();

        // まだ WordPress にない新しい記事（作業中の編集案）。公開されるまでは、本文ではタイトルだけになる（D-39）
        foreach (ArticleDraft::where('blog_id', $blog->id)->whereNull('post_id')->whereNull('page_id')->active()->orderBy('id')->get(['id', 'title_raw']) as $draft) {
            if (filled($draft->title_raw)) {
                $lines[] = "- [[記事:下書き{$draft->id}]] {$draft->title_raw}：（まだ公開していない新しい記事）";
            }
        }

        return $lines === [] ? '（なし）' : implode("\n", $lines);
    }

    /**
     * この記事の画像（編集案で依頼した画像と、本文の目印にある画像。D-34）
     */
    protected function imageList(Blog $blog, ?ArticleDraft $draft, string $content): string
    {
        preg_match_all('/\[\[画像:(\d+)\]\]/u', $content, $matches);
        $images = Image::where('blog_id', $blog->id)
            ->where(fn ($query) => $query->whereIn('id', array_map('intval', $matches[1]))->when($draft !== null, fn ($q) => $q->orWhere('article_draft_id', $draft->id)))
            ->orderBy('id')->get();

        if ($images->isEmpty()) {
            return '（なし）';
        }

        return $images->map(fn (Image $image) => "- [[画像:{$image->id}]] {$image->kind->label()}「{$image->title}」" . ($image->alt ? "（alt：{$image->alt}）" : '')
            . ($image->media_id ? '・WordPress に登録済み' : '・未登録'))->implode("\n");
    }

    /**
     * 品質診断に渡す、本文の画像の目印の中身（D-70）。目印のままでは図が見えず、図の項目を判定できないため、
     * 種類・題名・何を描くか・alt と、作ったかどうかを渡す
     */
    protected function imageDetails(Blog $blog, string $content): string
    {
        preg_match_all('/\[\[画像:(\d+)\]\]/u', $content, $matches);
        $images = $matches[1] === [] ? collect() : Image::where('blog_id', $blog->id)->whereIn('id', array_map('intval', $matches[1]))->orderBy('id')->get();
        if ($images->isEmpty()) {
            return '（本文に画像の目印はありません）';
        }

        return $images->map(fn (Image $image) => "- [[画像:{$image->id}]] {$image->kind->label()}「{$image->title}」"
            . ($image->hasFile() ? '（作成済み）' : '（作成中。BlogOS が内容のとおりに作る）')
            . (filled($image->description) ? "\n  - 描く内容：" . preg_replace('/\s+/u', ' ', $image->description) : '')
            . (filled($image->alt) ? "\n  - alt：{$image->alt}" : ''))->implode("\n");
    }
}
