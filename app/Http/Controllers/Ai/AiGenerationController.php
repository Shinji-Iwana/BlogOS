<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AiExecutionMethod;
use App\Enums\AiMode;
use App\Enums\RevisionScope;
use App\Http\Controllers\Concerns\ResolvesArticleTarget;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\AiGenerationRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;
use App\Support\QualityProfiles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BlogOSのAI機能の画面（ARCHITECTURE 18章、D-07-06、D-24）。
 *
 * 1. 実行モードと対象を選び、必要な情報と実行方式（手動／API）を入力する
 * 2. 手動実行：BlogOSが指示文を作る → 利用者がChatGPT等に貼り付けて実行する
 *    → 回答と、使ったモデル等を貼り付ける → BlogOSが評価・編集案として保存する
 * 3. API実行：BlogOSが指示文を作り、Jobで OpenAI API に送る → 回答を評価・編集案として保存する
 */
class AiGenerationController extends Controller
{
    use ResolvesArticleTarget;
    use UsesSelectedBlog;

    public function __construct(
        protected AiRunService $runService,
        protected AiGenerationRepository $generations,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        return view('ai.generations.index', [
            'blog'        => $blog,
            'generations' => $this->generations->listForBlog($blog->id),
        ]);
    }

    public function create(Request $request)
    {
        $blog = $this->selectedBlog();
        $mode = AiMode::tryFrom((string) $request->query('mode')) ?? AiMode::QualityDiagnosis;
        ['article' => $article, 'draft' => $draft] = $this->resolveTarget($blog->id, $request->query('target'));

        return view('ai.generations.create', [
            'blog'         => $blog,
            'mode'         => $mode,
            'modes'        => AiMode::cases(),
            'target'       => $this->targetKey($article, $draft),
            'article'      => $article,
            'draft'        => $draft,
            'scopes'       => RevisionScope::cases(),
            'articleTypes' => QualityProfiles::articleTypes($blog->quality_profile),
            'method'       => config("blogos.ai.methods.{$mode->value}", 'manual'),
            'api'          => $this->apiSummary($mode),
        ]);
    }

    public function store(Request $request)
    {
        $blog = $this->selectedBlog();

        $validated = $request->validate([
            'mode'            => ['required', Rule::enum(AiMode::class)],
            'target'          => ['nullable', 'string'],
            'revision_scope'  => ['nullable', Rule::enum(RevisionScope::class)],
            'target_type'     => ['nullable', Rule::in(['投稿', '固定ページ'])],
            'article_type'    => ['nullable', 'string', 'max:50'],
            'article_subtype' => ['nullable', 'string', 'max:50'],
            'main_keyword'    => ['nullable', 'string', 'max:191'],
            'sub_keywords'    => ['nullable', 'string', 'max:2000'],
            'search_intent'   => ['nullable', 'string', 'max:2000'],
            'notes'           => ['nullable', 'string', 'max:20000'],
            // 実行方式は実行ごとに選べる（D-24）。モデル・推論の深さが選べるものかは AiApiPolicy で確かめる
            'execution_method' => ['nullable', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
        ]);

        $mode = AiMode::from($validated['mode']);
        ['article' => $article, 'draft' => $draft] = $this->resolveTarget($blog->id, $validated['target'] ?? null);

        if ($mode === AiMode::NewArticle && blank($validated['main_keyword'] ?? null) && blank($validated['notes'] ?? null)) {
            return back()->withErrors(['main_keyword' => '新規記事の作成では、キーワードか、記事にしたい内容を入力してください。'])->withInput();
        }

        $types = QualityProfiles::articleTypes($blog->quality_profile);

        // 指示文の「人が提供した情報」（D-14-10：実体験・検証の内容は、人が提供したものだけを使う）
        $parameters = array_filter([
            '記事の種類'                         => $mode === AiMode::NewArticle ? ($validated['target_type'] ?? '投稿') : null,
            '記事種類'                           => isset($validated['article_type']) ? ($types['types'][$validated['article_type']] ?? $validated['article_type']) : null,
            '記事種類の値'                       => $validated['article_type'] ?? null,
            '細分類'                             => isset($validated['article_subtype']) ? ($types['subtypes'][$validated['article_subtype']] ?? $validated['article_subtype']) : null,
            'メインキーワード'                   => $validated['main_keyword'] ?? null,
            'サブキーワード'                     => $validated['sub_keywords'] ?? null,
            '検索意図'                           => $validated['search_intent'] ?? null,
            '補足（実体験・検証の結果・伝えたいこと）' => $validated['notes'] ?? null,
        ], fn ($value) => filled($value));

        try {
            $generation = $this->runService->start(
                $mode,
                $blog,
                $article,
                $draft,
                $parameters,
                isset($validated['revision_scope']) ? RevisionScope::from($validated['revision_scope']) : null,
                $request->user()?->id,
                isset($validated['execution_method']) ? AiExecutionMethod::from($validated['execution_method']) : null,
                $validated['model'] ?? null,
                $validated['reasoning_effort'] ?? null,
            );
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $generation = $this->generations->findForBlog($blog->id, $id);
        abort_if($generation === null, 404);

        return view('ai.generations.show', [
            'generation' => $generation,
            'manual'     => config('blogos.ai.manual'),
            'acceptance' => config('blogos.ai.acceptance_score'),
            'api'        => $this->apiSummary($generation->purpose),
        ]);
    }

    /**
     * 失敗したAPI実行を、同じ指示文でもう一度実行する（料金がかかる）
     */
    public function retry(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $generation = $this->generations->findForBlog($blog->id, $id);
        abort_if($generation === null, 404);

        try {
            $retry = $this->runService->retryApi($generation, $request->user()?->id);
        } catch (AiException $e) {
            return redirect()->route('ai.generations.show', ['id' => $id])->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('ai.generations.show', ['id' => $retry->id])->with('status', "実行記録 #{$id} と同じ指示文で、API実行を始めました。");
    }

    /**
     * API実行の設定と、今月の費用の目安（画面の表示用）
     */
    protected function apiSummary(AiMode $mode): array
    {
        return [
            'configured' => $this->apiPolicy->isConfigured(),
            'models'     => $this->apiPolicy->models(),
            'defaults'   => $this->apiPolicy->defaults($mode),
            'spent'      => $this->apiPolicy->spentThisMonth(),
            'budget'     => $this->apiPolicy->monthlyBudget(),
            'maxOutput'  => $this->apiPolicy->maxOutputTokens(),
        ];
    }

    public function submit(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $generation = $this->generations->findForBlog($blog->id, $id);
        abort_if($generation === null, 404);

        $validated = $request->validate([
            'output'           => ['required', 'string'],
            'service_plan'     => ['nullable', 'string', 'max:100'],
            'model'            => ['required', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
        ], [
            'model.required' => '使ったモデル（画面に表示されたモデル名）を入力してください（生成方法の記録。D-07-07）。',
        ]);

        try {
            $this->runService->submitManualOutput($generation, $validated['output'], $validated['service_plan'] ?? null, $validated['model'], $validated['reasoning_effort'] ?? null, $request->user()?->id);
        } catch (AiException $e) {
            return redirect()->route('ai.generations.show', ['id' => $id])->withErrors(['ai' => "回答を取り込めませんでした：{$e->getMessage()}"]);
        }

        return redirect()->route('ai.generations.show', ['id' => $id])->with('status', '回答を取り込みました。');
    }

    public function cancel(int $id)
    {
        $blog = $this->selectedBlog();
        $generation = $this->generations->findForBlog($blog->id, $id);
        abort_if($generation === null, 404);

        $this->runService->cancel($generation);

        return redirect()->route('ai.generations.show', ['id' => $id])->with('status', '取り消しました。');
    }
}
