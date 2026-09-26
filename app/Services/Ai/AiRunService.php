<?php

namespace App\Services\Ai;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\EvaluatorType;
use App\Enums\PushResourceType;
use App\Enums\RevisionScope;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\AiGenerationRepository;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleManagementSuggestionRepository;
use App\Support\QualityProfiles;
use App\Services\Ai\Executors\AiExecutor;
use App\Services\Ai\Executors\ApiExecutor;
use App\Services\Ai\Executors\ManualExecutor;
use App\Services\Articles\DraftService;
use App\Services\Push\PushException;
use App\Services\Quality\EvaluationService;
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
    public function start(AiMode $mode, Blog $blog, Post|Page|null $article, ?ArticleDraft $draft, array $parameters, ?RevisionScope $scope, ?int $userId, ?AiExecutionMethod $method = null, ?string $model = null, ?string $effort = null, bool $runApiNow = false): AiGeneration
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

        $built = $this->prompts->build($mode, $blog, $article, $draft, $parameters, $scope);
        if ($method === AiExecutionMethod::Api) {
            $this->apiPolicy->assertCanRun($model, $built['prompt']);
        }

        $generation = $this->generations->create([
            'blog_id'                 => $blog->id,
            'post_id'                 => $article instanceof Post ? $article->id : null,
            'page_id'                 => $article instanceof Page ? $article->id : null,
            'article_draft_id'        => $draft?->id,
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
            $result = $client->respond((string) $generation->model, $generation->input, $generation->reasoning_effort, $this->apiPolicy->maxOutputTokens());
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
     * 失敗したAPI実行を、同じ指示文でもう一度実行する（新しい実行記録を作る。前の記録の費用は残す）
     *
     * @throws AiException
     */
    public function retryApi(AiGeneration $generation, ?int $userId): AiGeneration
    {
        if ($generation->execution_method !== AiExecutionMethod::Api || $generation->status !== AiGenerationStatus::Failed) {
            throw new AiException('失敗したAPI実行だけを、実行し直せます。');
        }

        // 指示文は前の記録のものを使う。モデルは、応答のモデル名（日付付きなど）ではなく、選べるモデル名に戻す
        $model = $this->selectableModel((string) $generation->model);
        $this->apiPolicy->assertSelectable($model, (string) $generation->reasoning_effort);
        $this->apiPolicy->assertCanRun($model, $generation->input);

        $retry = $this->generations->create($generation->only([
            'blog_id', 'post_id', 'page_id', 'article_draft_id', 'purpose', 'revision_scope', 'parameters', 'execution_method', 'provider',
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
     * @param array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int} $usage
     */
    protected function usageAttributes(string $model, array $usage): array
    {
        return [
            'input_tokens'        => $usage['input_tokens'],
            'cached_input_tokens' => $usage['cached_input_tokens'],
            'output_tokens'       => $usage['output_tokens'],
            'reasoning_tokens'    => $usage['reasoning_tokens'],
            'estimated_cost'      => $this->apiPolicy->cost($this->selectableModel($model), $usage['input_tokens'], $usage['cached_input_tokens'], $usage['output_tokens']),
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
            // SEO分析・構成作成は、出力を記録するだけ（段階0：分析・提案）
            default                  => null,
        };
    }

    protected function saveDiagnosis(AiGeneration $generation, Blog $blog, ?int $userId): void
    {
        $parsed = $this->parser->diagnosis((string) $generation->output);
        $target = $generation->draft ?? $generation->post ?? $generation->page;

        $this->evaluationService->save($blog, $target, EvaluatorType::Ai, $parsed['judgments'], $parsed['comments'], $parsed['summary'], $generation->id, $userId);
    }

    protected function saveRevision(AiGeneration $generation, ?int $userId): void
    {
        $sections = $this->parser->article((string) $generation->output);
        $draft = $generation->draft;

        if ($draft === null) {
            $article = $generation->post ?? $generation->page ?? throw new AiException('対象の記事が見つかりません。');
            $draft = $this->draftService->createFromArticle($article, $generation->revision_scope, $userId, $generation->id);
        }

        $this->draftService->applyAiOutput($draft, [
            'title_raw'        => $sections['タイトル'],
            'excerpt_raw'      => $sections['抜粋'] ?? $draft->excerpt_raw,
            'meta_description' => $sections['メタディスクリプション'] ?? $draft->meta_description,
            'content_raw'      => $sections['本文'],
        ], $generation, $userId);
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

        $this->draftService->applyAiOutput($draft, [
            'title_raw'   => $sections['タイトル'],
            'slug'        => $slug !== '' ? $slug : null,
            'excerpt_raw'      => $sections['抜粋'] ?? '',
            'meta_description' => $sections['メタディスクリプション'] ?? '',
            'content_raw'      => $sections['本文'],
        ], $generation, $userId);
    }

    protected function executor(AiExecutionMethod $method): AiExecutor
    {
        return app($method === AiExecutionMethod::Api ? ApiExecutor::class : ManualExecutor::class);
    }
}
