<?php

namespace App\Services\Ai;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\EvaluatorType;
use App\Enums\ImageKind;
use App\Enums\PushResourceType;
use App\Enums\RevisionScope;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Category;
use App\Models\CategoryLaunchChild;
use App\Models\Image;
use App\Models\TopicSuggestion;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\AiGenerationRepository;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleManagementSuggestionRepository;
use App\Repositories\ImageRepository;
use App\Support\QualityProfiles;
use App\Services\Ai\Executors\AiExecutor;
use App\Services\Ai\Executors\ApiExecutor;
use App\Services\Ai\Executors\ManualExecutor;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\ArticleImageRequestService;
use App\Services\Articles\DraftService;
use App\Services\Images\ImageAiResultService;
use App\Services\Materials\MaterialAiResultService;
use App\Services\Push\PushException;
use App\Services\Topics\TopicPlanningResultService;
use App\Services\Quality\EvaluationService;
use App\Services\Quality\RevisionFindingService;
use Illuminate\Support\Facades\DB;

/**
 * BlogOSのAI機能の実行（ARCHITECTURE 18-2・18-3、D-07-01〜D-07-07）。
 *
 * AIに許可するのは、分析・提案（段階0）と、編集案の作成（段階1）まで。WordPressへの反映は人が承認する。
 * AIは人が画面で実行したときだけ動く。1回の指示につき1回だけ実行する。
 */
class AiRunService
{
    public function __construct(
        protected PromptBuilder $prompts,
        protected AiOutputParser $parser,
        protected AiGenerationRepository $generations,
        protected ArticleDraftRepository $drafts,
        protected DraftService $draftService,
        protected EvaluationService $evaluationService,
        protected AiApiPolicy $apiPolicy,
        protected ArticleManagementSuggestionRepository $suggestions,
        protected MaterialAiResultService $materialResults,
        protected ImageAiResultService $imageResults,
        protected ArticleHtmlFinisher $finisher,
        protected ArticleImageRequestService $imageRequests,
        protected ImageRepository $imageRepository,
    ) {
    }

    /**
     * 指示文を作り、実行を始める（手動実行では、回答の貼り付けを待つ状態になる。API実行では、Jobを登録する）。
     *
     * 実行方式・モデル・推論の深さを省略した場合は、設定（config/blogos.php の ai.methods・ai.api.defaults）に従う。
     *
     * @param array<string, string|null> $parameters 人が画面で入力した情報（ラベル => 値）
     *
     * @throws AiException
     */
    public function start(AiMode $mode, Blog $blog, Post|Page|null $article, ?ArticleDraft $draft, array $parameters, ?RevisionScope $scope, ?int $userId, ?AiExecutionMethod $method = null, ?string $model = null, ?string $effort = null, bool $runApiNow = false, ?Material $material = null, bool $webSearch = false, ?Image $image = null): AiGeneration
    {
        if ($blog->isArchived()) {
            throw new AiException('アーカイブしたブログでは実行できません。');
        }

        $article ??= $draft?->article();

        if ($mode === AiMode::NewArticle) {
            $article = null;
            $draft = null;
        } elseif (in_array($mode, [AiMode::QualityDiagnosis, AiMode::Revision, AiMode::SeoAnalysis], true) && $article === null && $draft === null) {
            throw new AiException('対象の記事または編集案を指定してください。');
        }

        // 教材（D-30）：調査は教材が、候補探しはカテゴリか教材の種類が、見直しはWordPressにある記事が対象
        if ($mode->isMaterialMode()) {
            $draft = null;
            if ($mode !== AiMode::MaterialReview) {
                $article = null;
            }
            match (true) {
                $mode === AiMode::MaterialResearch && ($material === null || $material->blog_id !== $blog->id) => throw new AiException('調べる教材を指定してください。'),
                $mode === AiMode::MaterialDiscovery && blank($parameters['カテゴリの値'] ?? null)               => throw new AiException('候補を探すカテゴリを指定してください。'),
                $mode === AiMode::MaterialReview && $article === null                                          => throw new AiException('教材を見直す記事を指定してください。'),
                default                                                                                         => null,
            };
        }
        if ($mode !== AiMode::MaterialResearch) {
            $material = null;
        }

        // 図の作成（D-32）：対象の画像が必要。記事は、図を載せる記事として任意。画像の生成は ImageGenerationService で行う
        if ($mode === AiMode::ImageGeneration) {
            throw new AiException('画像の生成は、画像の画面から行ってください。');
        }
        if ($mode === AiMode::ImageDesign) {
            $draft = null;
            if ($image === null || $image->blog_id !== $blog->id) {
                throw new AiException('図を作る画像を指定してください。');
            }
        } else {
            $image = null;
        }

        // 記事の企画（D-40）：カテゴリが対象（記事・編集案は対象にしない）
        if ($mode === AiMode::TopicPlanning) {
            $article = null;
            $draft = null;
            if (blank($parameters['カテゴリの値'] ?? null)) {
                throw new AiException('企画するカテゴリを指定してください。');
            }
        }

        // まだ WordPress にない新規記事の編集案の品質診断は、管理情報がないため、編集案を作ったときの記事種類・細分類で採点する（D-47）
        if ($mode === AiMode::QualityDiagnosis && $article === null && $draft?->ai_generation_id !== null) {
            $origin = (array) AiGeneration::find($draft->ai_generation_id)?->parameters;
            $parameters += array_filter(['記事種類の値' => $origin['記事種類の値'] ?? null, '細分類の値' => $origin['細分類の値'] ?? null]);
        }

        // 管理情報の案は、WordPressにある記事が対象（編集案の内容ではなく、記事の内容から作る。D-27）
        if ($mode === AiMode::ManagementSuggestion) {
            if ($article === null) {
                throw new AiException('管理情報の案は、WordPressにある記事を対象にしてください。');
            }
            $draft = null;
        }

        // 既存記事の改修は、作業中の編集案があればその編集案を対象にする（WordPressの内容で上書きしないため）
        if ($mode === AiMode::Revision && $draft === null && $article !== null) {
            $draft = $this->drafts->activeFor($article);
        }
        if ($mode === AiMode::Revision && $draft !== null && (! $draft->state->isActive() || $draft->isLocked())) {
            throw new AiException('作業中で、反映の結果待ちでない編集案だけを改修できます。');
        }

        $method ??= AiExecutionMethod::from(config("blogos.ai.methods.{$mode->value}", 'manual'));
        if ($method === AiExecutionMethod::Api) {
            $model ??= $this->apiPolicy->defaults($mode)['model'];
            $effort ??= $this->apiPolicy->defaults($mode)['effort'];
            $this->apiPolicy->assertSelectable($model, $effort);
        }

        // Web検索は、教材の調査・候補探しのAPI実行だけで使う
        $webSearch = $webSearch && $method === AiExecutionMethod::Api && $mode->canUseWebSearch();

        $built = $this->prompts->build($mode, $blog, $article, $draft, $parameters, $scope, $material, $webSearch, $image);
        if ($method === AiExecutionMethod::Api) {
            $this->apiPolicy->assertCanRun($model, $built['prompt'], $webSearch);
        }

        $generation = $this->generations->create([
            'blog_id'                 => $blog->id,
            'post_id'                 => $article instanceof Post ? $article->id : null,
            'page_id'                 => $article instanceof Page ? $article->id : null,
            'article_draft_id'        => $draft?->id,
            'material_id'             => $material?->id,
            'image_id'                => $image?->id,
            'use_web_search'          => $webSearch,
            'purpose'                 => $mode,
            'revision_scope'          => $mode === AiMode::Revision ? ($scope ?? RevisionScope::Minor) : null,
            'parameters'              => $parameters,
            'execution_method'        => $method,
            'provider'                => config($method === AiExecutionMethod::Manual ? 'blogos.ai.manual.provider' : 'blogos.ai.api.provider'),
            'model'                   => $method === AiExecutionMethod::Api ? $model : null,
            'reasoning_effort'        => $method === AiExecutionMethod::Api ? $effort : null,
            'template_key'            => $built['template']->key,
            'template_version'        => $built['template']->version,
            'quality_common_version'  => $built['standard']->commonVersion,
            'quality_profile'         => $built['standard']->profile,
            'quality_profile_version' => $built['standard']->profileVersion,
            'input'                   => $built['prompt'],
            'status'                  => AiGenerationStatus::Running,
            'requested_by'            => $userId,
        ]);

        // 記事改修：渡した指摘（番号付き）を記録する（対応・改修後の確認とつなぐ。D-47）
        // ロードマップに記事を載せる改修（D-70-06）は、指摘を渡さないため記録しない
        if ($mode === AiMode::Revision && blank($parameters[\App\Services\Articles\RoadmapLinkService::PARAMETER] ?? null)) {
            app(RevisionFindingService::class)->record($generation, $article, $draft);
        }

        // まとめて実行では、記事ごとのJobの中で、その場でAPIを呼ぶ（Jobを重ねない。D-25）
        if ($method === AiExecutionMethod::Api && $runApiNow) {
            $this->runApi($generation);
        } else {
            $this->executor($method)->start($generation);
        }

        return $generation->fresh();
    }

    /**
     * 手動実行の回答を取り込む。生成方法（利用プラン・モデル・推論の深さ）を記録する（D-07-07）。
     *
     * 取り込めなかった場合は失敗として記録し（出力は残す）、直した回答を貼り付け直せる。
     *
     * @throws AiException
     */
    public function submitManualOutput(AiGeneration $generation, string $output, ?string $servicePlan, string $model, ?string $effort, ?int $userId): void
    {
        if ($generation->execution_method !== AiExecutionMethod::Manual
            || ! in_array($generation->status, [AiGenerationStatus::WaitingOutput, AiGenerationStatus::Failed], true)) {
            throw new AiException('回答の貼り付けを待っている手動実行だけに、回答を取り込めます。');
        }

        $this->generations->update($generation, [
            'output'           => $output,
            'service_plan'     => $servicePlan,
            'model'            => $model,
            'reasoning_effort' => $effort,
        ]);

        $this->complete($generation, $userId);
    }

    /**
     * API実行（RunAiApiJob から呼ぶ）。OpenAI API に指示文を送り、回答を取り込む。
     *
     * 失敗は実行記録に残し、例外は投げない（料金がかかるため、Jobとしての自動の再実行はしない）。
     */
    public function runApi(AiGeneration $generation): void
    {
        // 同じJobが2回動いた場合に、二重に課金されないようにする
        if (! $this->generations->claimForApiRun($generation)) {
            return;
        }

        $client = new OpenAiClient((string) config('services.openai.key'), (string) config('services.openai.base_url'), (int) config('blogos.ai.api.timeout'));

        try {
            $result = $client->respond((string) $generation->model, $generation->input, $generation->reasoning_effort, $this->apiPolicy->maxOutputTokens(),
                $generation->use_web_search ? $this->apiPolicy->webSearch() : null);
        } catch (OpenAiException $e) {
            $this->generations->update($generation, [
                'status'       => AiGenerationStatus::Failed,
                'error'        => $e->getMessage(),
                'completed_at' => now(),
            ] + ($e->usage !== null ? $this->usageAttributes((string) $generation->model, $e->usage) : []));

            return;
        }

        // モデルは、応答に含まれる正確なモデル名を記録する（D-07-07）
        $this->generations->update($generation, [
            'output'               => $result['text'],
            'model'                => $result['model'],
            'provider_response_id' => $result['response_id'],
        ] + $this->usageAttributes((string) $generation->model, $result));

        try {
            $this->complete($generation, $generation->requested_by);
        } catch (AiException) {
            // 取り込めなかった理由は、complete() が実行記録に残している
        }
    }

    /**
     * 回答を取り込めずに失敗したAPI実行を、保存済みの回答でもう一度取り込む（APIは呼ばないため、料金はかからない）
     *
     * @throws AiException
     */
    public function reprocessApiOutput(AiGeneration $generation, ?int $userId): void
    {
        if ($generation->execution_method !== AiExecutionMethod::Api || $generation->status !== AiGenerationStatus::Failed || blank($generation->output)
            || $generation->purpose === AiMode::ImageGeneration) {
            throw new AiException('回答を取り込めずに失敗したAPI実行だけを、取り込み直せます。');
        }

        $this->complete($generation, $userId);
    }

    /**
     * 失敗したAPI実行を、同じ指示文でもう一度実行する（新しい実行記録を作る。前の記録の費用は残す）
     *
     * @throws AiException
     */
    public function retryApi(AiGeneration $generation, ?int $userId): AiGeneration
    {
        if ($generation->execution_method !== AiExecutionMethod::Api || $generation->status !== AiGenerationStatus::Failed) {
            throw new AiException('失敗したAPI実行だけを、実行し直せます。');
        }
        // 画像の生成は、画像の画面から作り直す（D-32）
        if ($generation->purpose === AiMode::ImageGeneration) {
            throw new AiException('画像の生成は、画像の画面から作り直してください。');
        }

        // 指示文は前の記録のものを使う。モデルは、応答のモデル名（日付付きなど）ではなく、選べるモデル名に戻す
        $model = $this->selectableModel((string) $generation->model);
        $this->apiPolicy->assertSelectable($model, (string) $generation->reasoning_effort);
        $this->apiPolicy->assertCanRun($model, $generation->input, (bool) $generation->use_web_search);

        $retry = $this->generations->create($generation->only([
            'blog_id', 'post_id', 'page_id', 'article_draft_id', 'material_id', 'image_id', 'use_web_search', 'purpose', 'revision_scope', 'parameters', 'execution_method', 'provider',
            'reasoning_effort', 'template_key', 'template_version', 'quality_common_version', 'quality_profile', 'quality_profile_version', 'input',
        ]) + ['model' => $model, 'status' => AiGenerationStatus::Running, 'requested_by' => $userId]);

        $this->executor(AiExecutionMethod::Api)->start($retry);

        return $retry;
    }

    /**
     * Job自体が失敗した（時間切れなど）場合に、実行中のままにしない
     */
    public function markApiFailed(AiGeneration $generation, string $error): void
    {
        if ($generation->status === AiGenerationStatus::Running) {
            $this->generations->update($generation, ['status' => AiGenerationStatus::Failed, 'error' => $error, 'completed_at' => now()]);
        }
    }

    /**
     * 保存済みの出力を、評価・編集案として取り込み、成功・失敗を記録する
     *
     * @throws AiException
     */
    protected function complete(AiGeneration $generation, ?int $userId): void
    {
        try {
            DB::transaction(fn () => $this->process($generation->fresh(['blog', 'post', 'page', 'draft']), $userId));
        } catch (AiException|PushException $e) {
            $this->generations->update($generation, ['status' => AiGenerationStatus::Failed, 'error' => $e->getMessage(), 'completed_at' => now()]);

            throw new AiException($e->getMessage());
        }

        $this->generations->update($generation, ['status' => AiGenerationStatus::Succeeded, 'error' => null, 'completed_at' => now()]);
    }

    /**
     * @param array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, web_search_calls?: int} $usage
     */
    protected function usageAttributes(string $model, array $usage): array
    {
        $webSearchCalls = (int) ($usage['web_search_calls'] ?? 0);

        return [
            'input_tokens'        => $usage['input_tokens'],
            'cached_input_tokens' => $usage['cached_input_tokens'],
            'output_tokens'       => $usage['output_tokens'],
            'reasoning_tokens'    => $usage['reasoning_tokens'],
            'web_search_calls'    => $webSearchCalls ?: null,
            'estimated_cost'      => $this->apiPolicy->cost($this->selectableModel($model), $usage['input_tokens'], $usage['cached_input_tokens'], $usage['output_tokens'], $webSearchCalls),
        ];
    }

    /**
     * 応答のモデル名（例：gpt-6-sol-2026-09-22）を、設定のモデル名（gpt-6-sol）に戻す
     */
    protected function selectableModel(string $model): string
    {
        foreach (array_keys($this->apiPolicy->models()) as $name) {
            if ($model === $name || str_starts_with($model, "{$name}-")) {
                return $name;
            }
        }

        return $model;
    }

    public function cancel(AiGeneration $generation): void
    {
        if (in_array($generation->status, [AiGenerationStatus::WaitingOutput, AiGenerationStatus::Failed], true)) {
            $this->generations->update($generation, ['status' => AiGenerationStatus::Cancelled, 'completed_at' => now()]);
        }
    }

    /**
     * 出力を、実行モードに応じて評価・編集案として保存する
     *
     * @throws AiException
     * @throws PushException
     */
    protected function process(AiGeneration $generation, ?int $userId): void
    {
        $blog = $generation->blog;

        match ($generation->purpose) {
            AiMode::QualityDiagnosis => $this->saveDiagnosis($generation, $blog, $userId),
            AiMode::Revision         => $this->saveRevision($generation, $userId),
            AiMode::NewArticle       => $this->saveNewArticle($generation, $blog, $userId),
            AiMode::ManagementSuggestion => $this->saveManagementSuggestion($generation, $blog),
            // 教材（D-30）：人が確認する案として保存する
            AiMode::MaterialResearch  => $this->materialResults->saveResearch($generation),
            AiMode::MaterialDiscovery => $this->materialResults->saveDiscovery($generation),
            AiMode::MaterialReview    => $this->materialResults->saveReview($generation),
            // 図の作成（D-32）
            AiMode::ImageDesign       => $this->imageResults->saveDesign($generation),
            // 記事の企画（D-40）：人が確認する案として保存する
            AiMode::TopicPlanning     => app(TopicPlanningResultService::class)->save($generation),
            // SEO分析・構成作成は、出力を記録するだけ（段階0：分析・提案）
            default                  => null,
        };
    }

    protected function saveDiagnosis(AiGeneration $generation, Blog $blog, ?int $userId): void
    {
        $parsed = $this->parser->diagnosis((string) $generation->output);
        $target = $generation->draft ?? $generation->post ?? $generation->page;

        $parameters = (array) $generation->parameters;
        $evaluation = $this->evaluationService->save($blog, $target, EvaluatorType::Ai, $parsed['judgments'], $parsed['comments'], $parsed['summary'], $generation->id, $userId,
            articleType: $parameters['記事種類の値'] ?? null, findings: $parsed['findings'], articleSubtype: $parameters['細分類の値'] ?? null);

        // 編集案の診断では、前回の改修の指摘が解消したかを記録する（D-47）
        if ($target instanceof ArticleDraft) {
            app(RevisionFindingService::class)->applyChecks($target, $evaluation, $parsed['findings_check']);
        }
    }

    protected function saveRevision(AiGeneration $generation, ?int $userId): void
    {
        $sections = $this->parser->article((string) $generation->output);
        $draft = $generation->draft;

        if ($draft === null) {
            $article = $generation->post ?? $generation->page ?? throw new AiException('対象の記事が見つかりません。');
            $draft = $this->draftService->createFromArticle($article, $generation->revision_scope, $userId, $generation->id);
        }

        // 指摘ごとの対応（D-47）
        app(RevisionFindingService::class)->applyResponses($generation, $draft, $this->parser->findingResponses($sections['指摘への対応'] ?? null));

        // タイトル・メタディスクリプションは、その指摘を渡したときだけ変える。指摘がなければ今のまま（基準を満たしているものを崩さないため。D-70）。
        // 指摘を記録していない改修（評価がない記事など）は、これまでどおり AI の出力を使う
        $findingKeys = \App\Models\RevisionFinding::where('ai_generation_id', $generation->id)->pluck('item_key')->all();
        // ロードマップに記事を載せる改修（D-70-06）は、タイトル・メタディスクリプションを変えない
        $hasEvaluation = $findingKeys !== [] || filled(((array) $generation->parameters)[\App\Services\Articles\RoadmapLinkService::PARAMETER] ?? null)
            || app(RevisionFindingService::class)->collect($generation->post ?? $generation->page, $generation->draft)['evaluation'] !== null;
        $keepTitle = $hasEvaluation && filled($draft->title_raw) && array_intersect($findingKeys, ['seo.title_keyword', 'seo.title_appeal', 'seo.title_form']) === [];
        $keepMeta = $hasEvaluation && filled($draft->meta_description) && ! in_array('seo.meta_description', $findingKeys, true);

        $this->applyArticleOutput($draft, $sections, [
            'title_raw'        => $keepTitle ? $draft->title_raw : $sections['タイトル'],
            'excerpt_raw'      => $sections['抜粋'] ?? $draft->excerpt_raw,
            'meta_description' => $keepMeta ? $draft->meta_description : ($sections['メタディスクリプション'] ?? $draft->meta_description),
        ], $generation, $userId);
    }

    /**
     * 記事改修・新規記事の出力を編集案に取り込む（D-34）。画像の依頼から画像の登録を作り、本文を仕上げ（目印の置き換え・広告の挿入など）、
     * 図解は続けて API で図を作る
     *
     * @param array<string, string> $sections
     * @param array<string, mixed> $values 本文以外の値
     *
     * @throws PushException
     */
    protected function applyArticleOutput(ArticleDraft $draft, array $sections, array $values, AiGeneration $generation, ?int $userId): void
    {
        $requests = $this->imageRequests->create($draft, $this->parser->imageRequests($sections['画像の依頼'] ?? null), $userId);
        $finished = $this->finisher->finish($generation->blog, $this->imageRequests->mapKeys($sections['本文'], $requests['map']));

        $this->draftService->applyAiOutput($draft, $values + ['content_raw' => $finished['content']], $generation, $userId);

        $notes = array_merge($requests['notes'], $finished['notes'], $this->startDiagramDesigns($draft, $requests['images'], $generation, $userId));

        // 教材を紹介しなかった場合は、理由を確かめられるように知らせる（D-35-04）
        if (! str_contains($sections['本文'], '[[教材:') && ! str_contains($finished['content'], 'rel="nofollow sponsored"')) {
            $notes[] = 'この編集案には、教材の紹介がありません。理由は AI 実行記録の「変更点」を確認してください。情報（学べる内容など）を調べていない教材は、候補に入りません（教材の画面の「AIで調べる」で調べ、案を登録してください）。';
        }
        $draft->forceFill(['finish_notes' => $notes !== [] ? $notes : null])->save();
    }

    /**
     * AIが依頼した図解を、API で図にする（SVG。費用が小さいため自動。D-34）。作れない場合は、画像の画面から作れるように案のまま残す
     *
     * @param list<Image> $images
     * @return list<string> 人に伝えること
     */
    protected function startDiagramDesigns(ArticleDraft $draft, array $images, AiGeneration $generation, ?int $userId): array
    {
        $diagrams = array_values(array_filter($images, fn (Image $image) => $image->kind === ImageKind::Diagram));
        if ($diagrams === []) {
            return [];
        }
        if (! $this->apiPolicy->isConfigured()) {
            return ['APIキーが設定されていないため、図解は作っていません。画像の画面の「AIで図を作る」から作ってください。'];
        }

        $notes = [];
        foreach ($diagrams as $image) {
            try {
                $this->start(AiMode::ImageDesign, $generation->blog, $generation->post ?? $generation->page, null,
                    ['形式' => 'SVG の図', '形式の値' => 'svg'], null, $userId, AiExecutionMethod::Api, image: $image);
            } catch (AiException $e) {
                $image->update(['ai_note' => "図を自動で作れませんでした：{$e->getMessage()}"]);
                $notes[] = "図解「{$image->title}」を自動で作れませんでした：{$e->getMessage()}";
            }
        }

        return $notes;
    }

    /**
     * 管理情報の案を保存する。記事種類・細分類は、ブログ別の定義にある値だけを残す（人が確認して登録する。D-27）
     */
    protected function saveManagementSuggestion(AiGeneration $generation, Blog $blog): void
    {
        $parsed = $this->parser->managementSuggestion((string) $generation->output);
        $article = $generation->post ?? $generation->page ?? throw new AiException('対象の記事が見つかりません。');

        $definitions = QualityProfiles::articleTypes($blog->quality_profile);
        foreach (['article_type' => 'types', 'article_subtype' => 'subtypes'] as $field => $key) {
            if ($parsed[$field] !== null && $definitions[$key] !== [] && ! isset($definitions[$key][$parsed[$field]])) {
                $parsed['reason'] = trim(($parsed['reason'] ?? '') . "（AIが示した{$field}「{$parsed[$field]}」は定義にないため、空にしました）");
                $parsed[$field] = null;
            }
        }

        $this->suggestions->create($article, $parsed + ['ai_generation_id' => $generation->id]);
    }

    protected function saveNewArticle(AiGeneration $generation, Blog $blog, ?int $userId): void
    {
        $sections = $this->parser->article((string) $generation->output);
        $type = ($generation->parameters['記事の種類'] ?? '投稿') === '固定ページ' ? PushResourceType::Page : PushResourceType::Post;

        $draft = $this->draftService->createNew($blog, $type, $userId, $generation->id);

        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($sections['スラッグ'] ?? ''));

        // 人が選んだカテゴリと、そのカテゴリのアイキャッチ（子のカテゴリに設定がなければ親のカテゴリ。D-32-03）
        $category = $type === PushResourceType::Post && ctype_digit((string) ($generation->parameters['カテゴリの値'] ?? ''))
            ? Category::where('blog_id', $blog->id)->find((int) $generation->parameters['カテゴリの値']) : null;
        $eyecatch = $category !== null ? $this->imageRepository->eyecatchFor($category) : null;

        $this->applyArticleOutput($draft, $sections, array_filter([
            'title_raw'                   => $sections['タイトル'],
            'slug'                        => $slug !== '' ? $slug : null,
            'excerpt_raw'                 => $sections['抜粋'] ?? '',
            'meta_description'            => $sections['メタディスクリプション'] ?? '',
            'wordpress_category_ids'      => $category !== null ? [(int) $category->wordpress_id] : null,
            'wordpress_featured_media_id' => $eyecatch !== null ? (int) $eyecatch->wordpress_id : null,
        ], fn ($value) => $value !== null), $generation, $userId);

        // カテゴリの立ち上げ（D-41）：記事の案・子ロードマップと、作った編集案を結び付ける
        $parameters = (array) $generation->parameters;
        if (ctype_digit((string) ($parameters['記事の企画の値'] ?? ''))) {
            $suggestion = TopicSuggestion::where('blog_id', $blog->id)->find((int) $parameters['記事の企画の値']);
            $suggestion?->update(['article_draft_id' => $draft->id]);
            if ($suggestion?->launch_child_id !== null) {
                $draft->forceFill(['category_launch_child_id' => $suggestion->launch_child_id])->save();
            }
        }
        if (($parameters['記事種類の値'] ?? null) === 'child_roadmap' && ctype_digit((string) ($parameters['立ち上げの子の値'] ?? ''))) {
            $child = CategoryLaunchChild::find((int) $parameters['立ち上げの子の値']);
            if ($child !== null) {
                $child->update(['roadmap_draft_id' => $draft->id]);
                $draft->forceFill(['category_launch_child_id' => $child->id])->save();
            }
        }
    }

    protected function executor(AiExecutionMethod $method): AiExecutor
    {
        return app($method === AiExecutionMethod::Api ? ApiExecutor::class : ManualExecutor::class);
    }
}
