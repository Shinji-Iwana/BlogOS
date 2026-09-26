<?php

namespace App\Services\Ai;

use App\Enums\AiBatchItemStatus;
use App\Enums\AiBatchStatus;
use App\Enums\AiBatchTarget;
use App\Enums\AiBatchTrigger;
use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\ReevaluationReason;
use App\Enums\RevisionScope;
use App\Jobs\RunAiBatchItemJob;
use App\Models\AiBatch;
use App\Models\AiBatchItem;
use App\Models\AiGeneration;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\AiBatchRepository;
use App\Repositories\AiGenerationRepository;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleManagementSuggestionRepository;
use App\Services\Quality\ReevaluationDetector;

/**
 * BlogOSのAI機能のまとめて実行（D-25）。
 *
 * 選んだ記事を、1記事ずつAPI実行する（記事ごとにJobを登録し、Queueの処理で順に動く）。
 * 1記事ごとの実行（AiRunService）と同じ処理で、記事ごとにAI実行記録を作る。
 * APIキーがない・月の費用の上限を超える場合は、残りの記事を実行せずに止める。
 */
class AiBatchService
{
    /**
     * 改修範囲を、改修前の点数から自動で判別する（D-27-02）
     */
    public const SCOPE_BY_SCORE = 'auto';

    public function __construct(
        protected AiBatchRepository $batches,
        protected AiGenerationRepository $generations,
        protected AiRunService $runService,
        protected AiApiPolicy $apiPolicy,
        protected ReevaluationDetector $detector,
        protected ArticleDraftRepository $drafts,
        protected ArticleEvaluationRepository $evaluations,
        protected ArticleManagementSuggestionRepository $suggestions,
    ) {
    }

    /**
     * 対象の記事。待機中・実行中の記事は除く（同じ記事を重ねて実行しない）
     *
     * @return list<array{article: Post|Page, evaluation: ArticleEvaluation|null, reason: ReevaluationReason|null}>
     */
    public function targets(Blog $blog, AiMode $mode, AiBatchTarget $target, ?float $belowScore = null): array
    {
        if (! in_array($target, AiBatchTarget::selectableFor($mode), true)) {
            throw new AiException("{$mode->label()}では、「{$target->label()}」を対象にできません。");
        }

        $rows = match ($target) {
            AiBatchTarget::NeedsReevaluation => $this->detector->detect($blog),
            default                          => array_map(fn ($row) => $row + ['reason' => null], $this->detector->articlesWithLatestEvaluation($blog)),
        };

        // 管理情報の案：登録済みの記事と、確認待ちの案がある記事は除く（D-27）
        $managed = $target === AiBatchTarget::Unmanaged ? $this->suggestions->managedArticles($blog->id) : ['posts' => [], 'pages' => []];
        $pending = $mode === AiMode::ManagementSuggestion ? $this->suggestions->pendingArticles($blog->id) : ['posts' => [], 'pages' => []];

        $rows = array_filter($rows, function ($row) use ($target, $belowScore, $managed, $pending) {
            $key = $row['article'] instanceof Post ? 'posts' : 'pages';

            return match ($target) {
                AiBatchTarget::Unevaluated => $row['evaluation'] === null,
                AiBatchTarget::BelowScore  => $row['evaluation']?->score !== null && $row['evaluation']->score < (float) $belowScore,
                AiBatchTarget::Unmanaged   => ! isset($managed[$key][$row['article']->id]),
                default                    => true,
            } && ! isset($pending[$key][$row['article']->id]);
        });

        $active = $this->batches->activeArticles($blog->id);

        return array_values(array_filter($rows, fn ($row) => ! isset($active[$row['article'] instanceof Post ? 'posts' : 'pages'][$row['article']->id])));
    }

    /**
     * まとめて実行を登録する
     *
     * @param list<array{article: Post|Page, reason: ReevaluationReason|null}> $targets
     * @param array{below_score: float, model: string, effort: string, revision_scope: string}|null $followUpRevision
     *                                                                                               品質診断の後に、基準に満たない記事の編集案を続けて作る設定（D-26）
     *
     * @throws AiException
     */
    public function start(Blog $blog, AiMode $mode, AiBatchTrigger $trigger, AiBatchTarget $target, array $targets, string $model, string $effort, array $targetParameters, ?int $userId, ?array $followUpRevision = null, ?int $parentBatchId = null): AiBatch
    {
        if ($blog->isArchived()) {
            throw new AiException('アーカイブしたブログでは実行できません。');
        }
        if (! in_array($mode, [AiMode::QualityDiagnosis, AiMode::Revision, AiMode::ManagementSuggestion], true)) {
            throw new AiException('まとめて実行できるのは、品質診断・記事改修・管理情報の案だけです。');
        }
        if ($targets === []) {
            throw new AiException('対象の記事がありません。');
        }
        if (! $this->apiPolicy->isConfigured()) {
            throw new AiApiUnavailableException('OpenAIのAPIキーが設定されていません（.env の OPENAI_API_KEY）。まとめて実行はAPI実行だけです。');
        }
        $this->apiPolicy->assertSelectable($model, $effort);
        if ($followUpRevision !== null) {
            if ($mode !== AiMode::QualityDiagnosis) {
                throw new AiException('編集案を続けて作れるのは、品質診断のまとめて実行だけです。');
            }
            $this->apiPolicy->assertSelectable($followUpRevision['model'], $followUpRevision['effort']);
        }

        $batch = $this->batches->create([
            'blog_id'           => $blog->id,
            'parent_batch_id'   => $parentBatchId,
            'follow_up'         => $followUpRevision !== null ? ['revision' => $followUpRevision] : null,
            'purpose'           => $mode,
            'trigger'           => $trigger,
            'model'             => $model,
            'reasoning_effort'  => $effort,
            'target'            => $target,
            'target_parameters' => $targetParameters ?: null,
            'status'            => AiBatchStatus::Running,
            'requested_by'      => $userId,
        ], array_map(fn ($row) => [
            'post_id' => $row['article'] instanceof Post ? $row['article']->id : null,
            'page_id' => $row['article'] instanceof Page ? $row['article']->id : null,
            'reason'  => $row['reason'] ?? null,
        ], $targets));

        foreach ($batch->items()->pluck('id') as $itemId) {
            RunAiBatchItemJob::dispatch($itemId)->afterCommit();
        }

        return $batch;
    }

    /**
     * 1記事を実行する（RunAiBatchItemJob から呼ぶ）。失敗は記録し、例外は投げない
     */
    public function runItem(AiBatchItem $item): void
    {
        if (! $this->batches->claimItem($item)) {
            return;
        }

        $batch = $item->batch;
        $article = $item->article();

        try {
            if ($article === null || $article->status !== 'publish') {
                throw new AiException('記事が見つからないか、公開中ではなくなりました。');
            }
            // まとめて実行の改修では、作業中の編集案を上書きしない（人の作業を消さないため。D-26-03）
            if ($batch->purpose === AiMode::Revision && ($draft = $this->drafts->activeFor($article)) !== null) {
                throw new AiException("作業中の編集案 #{$draft->id} があるため、改修しませんでした（人の作業を上書きしないため）。");
            }

            // 記事改修：改修前の点数（記事の最新の評価）と改修範囲。「点数で自動判別」なら点数から決める（D-27-02）
            $scope = null;
            if ($batch->purpose === AiMode::Revision) {
                $before = $this->evaluations->latestFor($article, null)?->score;
                $setting = (string) ($batch->target_parameters['revision_scope'] ?? RevisionScope::Minor->value);
                $scope = $setting === self::SCOPE_BY_SCORE ? RevisionScope::byScore($before) : (RevisionScope::tryFrom($setting) ?? RevisionScope::Minor);
                $this->batches->updateItem($item, ['score_before' => $before, 'revision_scope' => $scope->value]);
            }

            $generation = $this->runService->start(
                $batch->purpose,
                $batch->blog,
                $article,
                null,
                [],
                $scope,
                $batch->requested_by,
                AiExecutionMethod::Api,
                $batch->model,
                $batch->reasoning_effort,
                runApiNow: true,
            );

            $succeeded = $generation->status === AiGenerationStatus::Succeeded;
            $this->batches->updateItem($item, [
                'ai_generation_id' => $generation->id,
                'status'           => $succeeded ? AiBatchItemStatus::Succeeded : AiBatchItemStatus::Failed,
                'message'          => $generation->error,
            ]);

            // 記事改修の後に、できた編集案を品質診断する（改修前後の点数を比べるため。D-27-01）
            if ($succeeded && $batch->purpose === AiMode::Revision) {
                $this->diagnoseDraft($item, $batch, $generation);
            }
        } catch (AiApiUnavailableException $e) {
            // APIキーがない・費用の上限：この記事も残りの記事も実行しない
            $this->batches->updateItem($item, ['status' => AiBatchItemStatus::Skipped, 'message' => $e->getMessage()]);
            $this->batches->stop($batch, AiBatchStatus::Stopped, $e->getMessage());
        } catch (AiException $e) {
            // この記事だけ実行できない（改修できない編集案など）
            $this->batches->updateItem($item, ['status' => AiBatchItemStatus::Skipped, 'message' => $e->getMessage()]);
        }

        $this->finishIfDone($batch);
    }

    /**
     * 改修でできた編集案を品質診断する。モデルは、元の品質診断のまとめて実行と同じもの（なければ品質診断の標準）。
     * 費用の上限の場合は、まとめて実行を止める（改修は成功のまま）。その他の失敗は記事のメッセージに残す
     */
    protected function diagnoseDraft(AiBatchItem $item, AiBatch $batch, AiGeneration $revision): void
    {
        $draft = $revision->createdDrafts()->latest('id')->first();
        if ($draft === null) {
            return;
        }

        $defaults = $this->apiPolicy->defaults(AiMode::QualityDiagnosis);
        $parent = $batch->parent;

        try {
            $diagnosis = $this->runService->start(
                AiMode::QualityDiagnosis,
                $batch->blog,
                null,
                $draft,
                [],
                null,
                $batch->requested_by,
                AiExecutionMethod::Api,
                $parent?->purpose === AiMode::QualityDiagnosis ? $parent->model : $defaults['model'],
                $parent?->purpose === AiMode::QualityDiagnosis ? $parent->reasoning_effort : $defaults['effort'],
                runApiNow: true,
            );
        } catch (AiApiUnavailableException $e) {
            $this->batches->updateItem($item, ['message' => "編集案の品質診断はしませんでした：{$e->getMessage()}"]);
            $this->batches->stop($batch, AiBatchStatus::Stopped, $e->getMessage());

            return;
        } catch (AiException $e) {
            $this->batches->updateItem($item, ['message' => "編集案の品質診断はできませんでした：{$e->getMessage()}"]);

            return;
        }

        $this->batches->updateItem($item, [
            'diagnosis_generation_id' => $diagnosis->id,
            'score_after'             => $diagnosis->evaluations()->latest('id')->value('score'),
            'message'                 => $diagnosis->error !== null ? "編集案の品質診断に失敗しました：{$diagnosis->error}" : null,
        ]);
    }

    /**
     * 全ての記事が終わったら完了にし、品質診断の後に続けて行う記事改修を登録する（D-26）
     */
    protected function finishIfDone(AiBatch $batch): void
    {
        if ($this->batches->completeIfFinished($batch)) {
            $this->startFollowUpRevision($batch->fresh());
        }
    }

    /**
     * 品質診断の結果、基準に満たなかった記事（点数が基準未満、または必須条件を満たさない）の編集案を作る。
     * 費用の上限・取り消しで止まった品質診断では行わない。登録できなかった理由は、元のまとめて実行に残す
     */
    public function startFollowUpRevision(AiBatch $batch): ?AiBatch
    {
        $settings = $batch->follow_up['revision'] ?? null;
        if ($settings === null || $batch->purpose !== AiMode::QualityDiagnosis || $batch->status !== AiBatchStatus::Completed) {
            return null;
        }

        $belowScore = (float) $settings['below_score'];
        $targets = [];
        foreach ($this->batches->succeededItemsWithEvaluation($batch) as $item) {
            $evaluation = $item->generation?->evaluations->sortByDesc('id')->first();
            $article = $item->article();
            if ($evaluation === null || $article === null) {
                continue;
            }
            if (($evaluation->score !== null && $evaluation->score < $belowScore) || $evaluation->required_conditions_passed === false) {
                $targets[] = ['article' => $article, 'reason' => null];
            }
        }

        if ($targets === []) {
            return null;
        }

        try {
            return $this->start(
                $batch->blog,
                AiMode::Revision,
                $batch->trigger,
                AiBatchTarget::AfterDiagnosis,
                $targets,
                $settings['model'],
                $settings['effort'],
                ['below_score' => $belowScore, 'revision_scope' => $settings['revision_scope'] ?? RevisionScope::Minor->value],
                $batch->requested_by,
                parentBatchId: $batch->id,
            );
        } catch (AiException $e) {
            $this->batches->noteStopReason($batch, "編集案を続けて作れませんでした：{$e->getMessage()}");

            return null;
        }
    }

    /**
     * Job自体が失敗した（時間切れなど）場合に、実行中のままにしない
     */
    public function markItemFailed(AiBatchItem $item, string $error): void
    {
        if ($item->ai_generation_id !== null && ($generation = $this->generations->find($item->ai_generation_id)) !== null) {
            $this->runService->markApiFailed($generation, $error);
        }
        if (! $item->status->isFinished()) {
            $this->batches->updateItem($item, ['status' => AiBatchItemStatus::Failed, 'message' => $error]);
        }

        $this->finishIfDone($item->batch);
    }

    /**
     * 取り消す。実行中の記事は最後まで実行し、待機中の記事は実行しない
     */
    public function cancel(AiBatch $batch): void
    {
        $this->batches->stop($batch, AiBatchStatus::Cancelled, '人が取り消しました。');
    }

    /**
     * 1記事あたりの費用の目安（同じ実行モード・モデルの過去のAPI実行の平均。なければ null）
     */
    public function averageCost(AiMode $mode, string $model): ?float
    {
        return $this->generations->averageApiCost($mode, $model);
    }
}
