<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AiBatchTarget;
use App\Enums\AiBatchTrigger;
use App\Enums\AiMode;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\AiBatchRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\AiException;
use App\Services\Ai\AutoReevaluationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BlogOSのAI機能のまとめて実行（D-25）。品質診断・記事改修を、選んだ記事に1記事ずつAPI実行する。
 */
class AiBatchController extends Controller
{
    use UsesSelectedBlog;

    /**
     * まとめて実行できる実行モード
     */
    public const MODES = [AiMode::QualityDiagnosis, AiMode::Revision, AiMode::ManagementSuggestion];

    public function __construct(
        protected AiBatchService $service,
        protected AiBatchRepository $batches,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        return view('ai.batches.index', [
            'blog'    => $blog,
            'batches' => $this->batches->listForBlog($blog->id)->map(fn ($batch) => [$batch, $this->batches->progress($batch)]),
        ]);
    }

    /**
     * 実行モード・対象・モデルを選び、対象の記事と費用の目安を確かめる
     */
    public function create(Request $request)
    {
        $blog = $this->selectedBlog();
        $mode = AiMode::tryFrom((string) $request->query('mode')) ?? AiMode::QualityDiagnosis;
        if (! in_array($mode, self::MODES, true)) {
            $mode = AiMode::QualityDiagnosis;
        }

        $targetOptions = AiBatchTarget::selectableFor($mode);
        $target = AiBatchTarget::tryFrom((string) $request->query('target'));
        $target = in_array($target, $targetOptions, true) ? $target : $targetOptions[0];
        $belowScore = (float) $request->query('below_score', config('blogos.ai.acceptance_score'));
        $defaults = $this->apiPolicy->defaults($mode);
        $model = (string) $request->query('model', $defaults['model']);
        $effort = (string) $request->query('reasoning_effort', $defaults['effort']);

        // 件数の上限（初めて試すときなど。空なら全て）
        $limit = $request->filled('limit') ? max(1, (int) $request->query('limit')) : null;
        $targets = array_slice($this->service->targets($blog, $mode, $target, $belowScore), 0, $limit);

        return view('ai.batches.create', [
            'limit'         => $limit,
            'blog'          => $blog,
            'mode'          => $mode,
            'target'        => $target,
            'targetOptions' => $targetOptions,
            'belowScore'    => $belowScore,
            'model'         => $model,
            'effort'        => $effort,
            'scopes'        => AutoReevaluationService::scopeOptions(),
            'targets'       => $targets,
            'averageCost'   => $this->service->averageCost($mode, $model),
            'revisionDefaults' => $this->apiPolicy->defaults(AiMode::Revision),
            'acceptance'       => (float) config('blogos.ai.acceptance_score'),
            'api'           => [
                'configured' => $this->apiPolicy->isConfigured(),
                'models'     => $this->apiPolicy->models(),
                'spent'      => $this->apiPolicy->spentThisMonth(),
                'budget'     => $this->apiPolicy->monthlyBudget(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $blog = $this->selectedBlog();

        $validated = $request->validate([
            'mode'             => ['required', Rule::in(array_map(fn (AiMode $mode) => $mode->value, self::MODES))],
            'target'           => ['required', Rule::enum(AiBatchTarget::class)],
            'below_score'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'limit'            => ['nullable', 'integer', 'min:1'],
            'model'            => ['required', 'string', 'max:100'],
            'reasoning_effort' => ['required', 'string', 'max:30'],
            'revision_scope'   => ['nullable', Rule::in(array_keys(AutoReevaluationService::scopeOptions()))],
            // 品質診断の後に、基準に満たない記事の編集案を続けて作る（D-26）
            'follow_up_revision'          => ['nullable', 'boolean'],
            'follow_up_below_score'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'follow_up_model'             => ['nullable', 'string', 'max:100'],
            'follow_up_reasoning_effort'  => ['nullable', 'string', 'max:30'],
        ]);

        $mode = AiMode::from($validated['mode']);
        $target = AiBatchTarget::from($validated['target']);
        $belowScore = isset($validated['below_score']) ? (float) $validated['below_score'] : null;

        try {
            $batch = $this->service->start(
                $blog,
                $mode,
                AiBatchTrigger::Manual,
                $target,
                array_slice($this->service->targets($blog, $mode, $target, $belowScore), 0, isset($validated['limit']) ? (int) $validated['limit'] : null),
                $validated['model'],
                $validated['reasoning_effort'],
                array_filter([
                    'below_score'    => $target === AiBatchTarget::BelowScore ? $belowScore : null,
                    'revision_scope' => $mode === AiMode::Revision ? ($validated['revision_scope'] ?? AiBatchService::SCOPE_BY_SCORE) : null,
                ], fn ($value) => $value !== null),
                $request->user()?->id,
                $mode === AiMode::QualityDiagnosis && ($validated['follow_up_revision'] ?? false) ? [
                    'below_score'    => (float) ($validated['follow_up_below_score'] ?? config('blogos.ai.acceptance_score')),
                    'model'          => $validated['follow_up_model'] ?? $this->apiPolicy->defaults(AiMode::Revision)['model'],
                    'effort'         => $validated['follow_up_reasoning_effort'] ?? $this->apiPolicy->defaults(AiMode::Revision)['effort'],
                    'revision_scope' => $validated['revision_scope'] ?? AiBatchService::SCOPE_BY_SCORE,
                ] : null,
            );
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.batches.show', ['id' => $batch->id])->with('status', "{$batch->total_count}件の実行を登録しました。");
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $batch = $this->batches->findForBlog($blog->id, $id);
        abort_if($batch === null, 404);

        return view('ai.batches.show', [
            'batch'    => $batch,
            'items'    => $this->batches->items($batch),
            'progress' => $this->batches->progress($batch),
        ]);
    }

    public function cancel(int $id)
    {
        $blog = $this->selectedBlog();
        $batch = $this->batches->findForBlog($blog->id, $id);
        abort_if($batch === null, 404);

        $this->service->cancel($batch);

        return redirect()->route('ai.batches.show', ['id' => $id])->with('status', '取り消しました（実行中の記事は最後まで実行します）。');
    }
}
