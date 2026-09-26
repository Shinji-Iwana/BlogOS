<?php

namespace App\Http\Controllers\Quality;

use App\Enums\EvaluatorType;
use App\Enums\Judgment;
use App\Http\Controllers\Concerns\ResolvesArticleTarget;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleEvaluationRepository;
use App\Services\Quality\EvaluationException;
use App\Services\Quality\EvaluationService;
use App\Services\Quality\ScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 記事の品質評価（人による評価と確定。D-06-02、D-07-03）。
 *
 * AIの評価をもとに評価する場合は、AIの判定を初期値として表示し、人が見直して保存する。
 */
class EvaluationController extends Controller
{
    use ResolvesArticleTarget;
    use UsesSelectedBlog;

    public function __construct(
        protected EvaluationService $evaluationService,
        protected ArticleEvaluationRepository $evaluations,
    ) {
    }

    public function create(Request $request)
    {
        $blog = $this->selectedBlog();
        ['article' => $article, 'draft' => $draft] = $this->resolveTarget($blog->id, $request->query('target'));
        abort_if($article === null && $draft === null, 404);

        $standard = $this->evaluationService->standardFor($blog);
        $articleType = $this->evaluationService->articleTypeFor($article);

        // 元にする評価（AIの評価など）の判定を初期値にする
        $base = $request->filled('from') ? $this->evaluations->findForBlog($blog->id, (int) $request->query('from')) : null;

        return view('quality.evaluations.create', [
            'target'      => $this->targetKey($article, $draft),
            'article'     => $article,
            'draft'       => $draft,
            'standard'    => $standard,
            'articleType' => $articleType,
            'applicable'  => $standard->applicableItems($articleType),
            'base'        => $base?->details->keyBy('item_key'),
            'baseSummary' => $base?->summary,
        ]);
    }

    public function store(Request $request)
    {
        $blog = $this->selectedBlog();
        ['article' => $article, 'draft' => $draft] = $this->resolveTarget($blog->id, $request->input('target'));
        abort_if($article === null && $draft === null, 404);

        $symbols = array_map(fn (Judgment $j) => $j->value, Judgment::cases());
        $validated = $request->validate([
            'judgments'   => ['required', 'array'],
            'judgments.*' => ['nullable', Rule::in($symbols)],
            'comments'    => ['nullable', 'array'],
            'comments.*'  => ['nullable', 'string', 'max:5000'],
            'summary'     => ['nullable', 'string', 'max:10000'],
            'confirm'     => ['nullable', 'boolean'],
        ]);

        $judgments = array_map(fn ($value) => Judgment::from($value), array_filter($validated['judgments']));

        try {
            $evaluation = $this->evaluationService->save(
                $blog,
                $draft ?? $article,
                EvaluatorType::Human,
                $judgments,
                $validated['comments'] ?? [],
                $validated['summary'] ?? null,
                null,
                $request->user()?->id,
                $request->boolean('confirm'),
            );
        } catch (EvaluationException $e) {
            return back()->withErrors(['evaluation' => $e->getMessage()])->withInput();
        }

        return redirect()->route('evaluations.show', ['id' => $evaluation->id])->with('status', '評価を保存しました。');
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $evaluation = $this->evaluations->findForBlog($blog->id, $id);
        abort_if($evaluation === null, 404);

        $standard = $this->evaluationService->standardFor($blog);

        return view('quality.evaluations.show', [
            'evaluation' => $evaluation,
            'standard'   => $standard,
            'verdict'    => ScoreCalculator::verdict($evaluation->score, $evaluation->required_conditions_passed),
            'target'     => $this->targetKey($evaluation->post ?? $evaluation->page, $evaluation->draft),
            // 評価後に品質基準が変わった場合は、表示の点数と現在の基準での点数が違うことがある
            'versionChanged' => $evaluation->quality_common_version !== $standard->commonVersion
                || $evaluation->quality_profile_version !== $standard->profileVersion,
        ]);
    }
}
