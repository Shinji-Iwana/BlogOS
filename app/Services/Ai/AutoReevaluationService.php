<?php

namespace App\Services\Ai;

use App\Enums\AiBatchTarget;
use App\Enums\AiBatchTrigger;
use App\Enums\AiMode;
use App\Enums\RevisionScope;
use App\Models\AiBatch;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\AiBatchRepository;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\BlogAiSettingRepository;
use Illuminate\Support\Carbon;

/**
 * 条件による自動の再評価（D-25-02、D-25-04）。毎日の処理（ai:auto-reevaluate）から呼ぶ。
 *
 * ブログのAIの設定で有効にした場合だけ、再評価の条件に当てはまる記事を品質診断する（API実行）。
 * 設定で有効にしていれば、診断の後に、基準に満たない記事の編集案を作る（段階1。D-26）。
 * 基準を満たすまで、改修と編集案の診断を、設定の回数まで繰り返す（D-65。AiBatchService::startNextRevisionRound）。
 * 編集案がある記事は、記事ではなく編集案を診断する。AI が作ったままの編集案は改修を続け、人が手を入れた編集案は診断だけ（D-65）。
 * WordPressへの反映は自動で行わない（D-07-01）。
 */
class AutoReevaluationService
{
    public function __construct(
        protected AiBatchService $batchService,
        protected AiBatchRepository $batches,
        protected BlogAiSettingRepository $settings,
        protected ArticleDraftRepository $drafts,
        protected ArticleEvaluationRepository $evaluations,
    ) {
    }

    /**
     * 1つのブログの自動の再評価を登録する。対象がない・今日の上限に達した場合は null
     *
     * @throws AiException
     */
    public function run(Blog $blog): ?AiBatch
    {
        $setting = $this->settings->forBlog($blog);
        if (! $setting->auto_reevaluation_enabled || $blog->isArchived()) {
            return null;
        }

        $remaining = $this->remainingToday($blog);
        // 編集案がある記事は、編集案を診断する。前回の診断から編集案が変わっていなければ、診断し直さない（費用をかけないため。D-65）
        $targets = array_values(array_filter(
            $this->batchService->targets($blog, AiMode::QualityDiagnosis, AiBatchTarget::NeedsReevaluation),
            fn (array $row) => ! $this->draftUnchangedSinceDiagnosis($row['article']),
        ));
        $targets = array_slice($targets, 0, $remaining);
        if ($targets === []) {
            return null;
        }

        return $this->batchService->start(
            $blog,
            AiMode::QualityDiagnosis,
            AiBatchTrigger::Auto,
            AiBatchTarget::Auto,
            $targets,
            $setting->auto_model,
            $setting->auto_reasoning_effort,
            // 編集案がある記事は、記事ではなく編集案を診断する（D-65）
            ['diagnose_drafts' => true],
            null,
            $setting->auto_revision_enabled
                ? $this->followUpRevision($setting->auto_revision_model, $setting->auto_revision_reasoning_effort, $setting->auto_revision_scope, $setting->auto_revision_max_rounds)
                : null,
        );
    }

    /**
     * 作業中の編集案があり、その編集案を最後に診断した後、編集案が変わっていないか
     */
    protected function draftUnchangedSinceDiagnosis(Post|Page $article): bool
    {
        $draft = $this->drafts->activeFor($article);
        if ($draft === null) {
            return false;
        }
        $evaluation = $this->evaluations->latestFor($article, $draft);

        return $evaluation !== null && $draft->updated_at !== null && $evaluation->created_at >= $draft->updated_at;
    }

    /**
     * 品質診断の後に、基準（受け入れの目安の点数）に満たない記事の編集案を作る設定（D-26）。
     * 改修範囲の初期値は「点数で自動判別」（D-27-02）。
     * max_rounds：基準を満たすまで、改修と診断を繰り返す上限の回数（D-65。画面から始めるまとめて実行は1回）
     *
     * @return array{below_score: float, model: string, effort: string, revision_scope: string, max_rounds: int}
     */
    public function followUpRevision(?string $model, ?string $effort, ?string $scope = null, ?int $maxRounds = null): array
    {
        $defaults = (array) config('blogos.ai.api.defaults.revision');

        return [
            'below_score'    => (float) config('blogos.ai.acceptance_score'),
            'model'          => $model ?: $defaults['model'],
            'effort'         => $effort ?: $defaults['effort'],
            'revision_scope' => $scope ?: AiBatchService::SCOPE_BY_SCORE,
            'max_rounds'     => max(1, min((int) config('blogos.ai.auto_reevaluation.max_revision_rounds'), $maxRounds ?? (int) config('blogos.ai.auto_reevaluation.default_revision_rounds'))),
        ];
    }

    /**
     * 画面で選べる改修範囲（点数で自動判別を含む）
     *
     * @return array<string, string> 値 => 名前
     */
    public static function scopeOptions(): array
    {
        $thresholds = (array) config('blogos.ai.revision_scope_by_score');
        $options = [AiBatchService::SCOPE_BY_SCORE => "点数で自動判別（{$thresholds['minor']}点以上：軽微な改善、{$thresholds['restructure']}点以上：構成の見直し、それ未満：全面改修）"];
        foreach (RevisionScope::cases() as $scope) {
            $options[$scope->value] = $scope->label();
        }

        return $options;
    }

    /**
     * 今日（日本時間）あと何記事を自動で再評価できるか
     */
    public function remainingToday(Blog $blog): int
    {
        $from = Carbon::now(config('blogos.display_timezone'))->startOfDay()->utc();

        return max(0, (int) config('blogos.ai.auto_reevaluation.daily_limit') - $this->batches->autoItemCountSince($blog->id, $from));
    }
}
