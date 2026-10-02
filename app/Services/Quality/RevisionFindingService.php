<?php

namespace App\Services\Quality;

use App\Enums\Judgment;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\Page;
use App\Models\Post;
use App\Models\RevisionFinding;
use App\Repositories\ArticleEvaluationRepository;
use Illuminate\Support\Collection;

/**
 * 指摘 → 改修での対応 → 改修後の確認を、指摘の番号でつなぐ（D-47 S3）。
 *
 * 1. 記事改修の指示文を作るとき：元にする評価（編集案の最新の評価、なければ記事の最新の評価）の、○ でない項目（要人間確認を除く）に
 *    番号を付けて「直すべきこと」として渡し、改修の実行に記録する
 * 2. 改修の出力の「指摘への対応」で、番号ごとの対応（直した・一部直した・直さなかった）を記録する
 * 3. 編集案の品質診断で、番号ごとに解消したか（解消・一部解消・未解消）を判定させて記録する
 */
class RevisionFindingService
{
    public function __construct(
        protected ArticleEvaluationRepository $evaluations,
        protected QualityStandardLoader $loader,
    ) {
    }

    /**
     * 改修で直すべき指摘（番号付き）
     *
     * @return array{evaluation: ArticleEvaluation|null, findings: list<array{number: int, item_key: string, judgment: Judgment, location: string|null, problem: string|null, fix: string|null, comment: string|null}>}
     */
    public function collect(Post|Page|null $article, ?ArticleDraft $draft): array
    {
        $evaluation = $this->evaluations->latestFor($article, $draft) ?? ($article ? $this->evaluations->latestFor($article, null) : null);
        if ($evaluation === null) {
            return ['evaluation' => null, 'findings' => []];
        }

        $findings = [];
        foreach ($evaluation->details->sortBy('id') as $detail) {
            if (! in_array($detail->judgment, [Judgment::Partial, Judgment::Bad], true)) {
                continue;
            }
            $findings[] = [
                'number'   => count($findings) + 1,
                'item_key' => $detail->item_key,
                'judgment' => $detail->judgment,
                'location' => $detail->location,
                'problem'  => $detail->problem,
                'fix'      => $detail->fix,
                'comment'  => $detail->comment,
            ];
        }

        return ['evaluation' => $evaluation, 'findings' => $findings];
    }

    /**
     * 記事改修の指示文の「優先して改善する点」
     */
    public function describe(Post|Page|null $article, ?ArticleDraft $draft, QualityStandard $standard): string
    {
        $collected = $this->collect($article, $draft);
        $evaluation = $collected['evaluation'];
        if ($evaluation === null) {
            return '（評価がありません。品質基準の全体を見て改善してください）';
        }

        $lines = ["（{$evaluation->created_at?->format('Y-m-d')} の" . $evaluation->evaluator_type->label() . 'の評価：' . ($evaluation->score ?? '-') . '点）'];
        foreach ($collected['findings'] as $finding) {
            $label = $standard->allItems()[$finding['item_key']]['label'] ?? $standard->required[$finding['item_key']]['label'] ?? '';
            $lines[] = "- 指摘{$finding['number']}：{$finding['item_key']}（{$label}）：{$finding['judgment']->label()}" . ($finding['comment'] ? " — {$finding['comment']}" : '');
            foreach (['location' => 'どこが', 'problem' => '何が足りないか', 'fix' => 'どう直すか'] as $field => $name) {
                if (filled($finding[$field])) {
                    $lines[] = "  - {$name}：{$finding[$field]}";
                }
            }
        }

        return count($lines) === 1 ? $lines[0] . "\n- 不足点はありません" : implode("\n", $lines);
    }

    /**
     * 記事改修の実行を始めたときに、渡した指摘を記録する
     */
    public function record(AiGeneration $generation, Post|Page|null $article, ?ArticleDraft $draft): void
    {
        $collected = $this->collect($article, $draft);
        foreach ($collected['findings'] as $finding) {
            RevisionFinding::create([
                'blog_id'              => $generation->blog_id,
                'ai_generation_id'     => $generation->id,
                'article_draft_id'     => $draft?->id,
                'source_evaluation_id' => $collected['evaluation']?->id,
                'number'               => $finding['number'],
                'item_key'             => $finding['item_key'],
                'judgment'             => $finding['judgment'],
                'location'             => $finding['location'],
                'problem'              => $finding['problem'],
                'fix'                  => $finding['fix'],
            ]);
        }
    }

    /**
     * 改修の出力の「指摘への対応」を記録する（編集案と結び付ける）
     *
     * @param array<int, array{status: string, note: string|null}> $responses 番号 => 対応
     */
    public function applyResponses(AiGeneration $generation, ArticleDraft $draft, array $responses): void
    {
        foreach (RevisionFinding::where('ai_generation_id', $generation->id)->get() as $finding) {
            $response = $responses[$finding->number] ?? null;
            $finding->update([
                'article_draft_id' => $draft->id,
                'response_status'  => $response['status'] ?? null,
                'response_note'    => $response['note'] ?? null,
            ]);
        }
    }

    /**
     * 編集案の品質診断で確かめる、前回の改修の指摘（指示文に入れる）
     */
    public function checkList(?ArticleDraft $draft): string
    {
        $findings = $draft !== null ? $this->latestFor($draft) : collect();
        if ($findings->isEmpty()) {
            return '（なし）';
        }

        return $findings->map(fn (RevisionFinding $finding) => "- 指摘{$finding->number}：{$finding->item_key}（改修前：{$finding->judgment->label()}）"
            . ($finding->problem ? "\n  - 何が足りなかったか：{$finding->problem}" : '')
            . ($finding->fix ? "\n  - どう直すか：{$finding->fix}" : '')
            . ($finding->response_status ? "\n  - 改修での対応：" . (RevisionFinding::RESPONSES[$finding->response_status] ?? $finding->response_status) . ($finding->response_note ? "（{$finding->response_note}）" : '') : ''))
            ->implode("\n");
    }

    /**
     * 編集案の品質診断の「前回の指摘の確認」を記録する
     *
     * @param array<int, array{status: string, note: string|null}> $checks 番号 => 確認
     */
    public function applyChecks(ArticleDraft $draft, ArticleEvaluation $evaluation, array $checks): void
    {
        foreach ($this->latestFor($draft) as $finding) {
            $check = $checks[$finding->number] ?? null;
            if ($check === null) {
                continue;
            }
            $finding->update(['check_status' => $check['status'], 'check_note' => $check['note'], 'check_evaluation_id' => $evaluation->id]);
        }
    }

    /**
     * 編集案の、いちばん新しい記事改修の指摘
     *
     * @return Collection<int, RevisionFinding>
     */
    public function latestFor(ArticleDraft $draft): Collection
    {
        $generationId = RevisionFinding::where('article_draft_id', $draft->id)->max('ai_generation_id');

        return $generationId !== null
            ? RevisionFinding::with(['sourceEvaluation:id,score,axis_scores', 'checkEvaluation:id,score,axis_scores'])->where('ai_generation_id', $generationId)->orderBy('number')->get()
            : collect();
    }
}
