<?php

namespace App\Services\Ai;

use App\Enums\AiBatchTarget;
use App\Enums\AiBatchTrigger;
use App\Enums\AiMode;
use App\Enums\RevisionScope;
use App\Models\AiBatch;
use App\Models\Blog;
use App\Repositories\AiBatchRepository;
use App\Repositories\BlogAiSettingRepository;
use Illuminate\Support\Carbon;

/**
 * 条件による自動の再評価（D-25-02、D-25-04）。毎日の処理（ai:auto-reevaluate）から呼ぶ。
 *
 * ブログのAIの設定で有効にした場合だけ、再評価の条件に当てはまる記事を品質診断する（API実行）。
 * 設定で有効にしていれば、診断の後に、基準に満たない記事の編集案を作る（段階1。D-26）。
 * WordPressへの反映は自動で行わない（D-07-01）。
 */
class AutoReevaluationService
{
    public function __construct(
        protected AiBatchService $batchService,
        protected AiBatchRepository $batches,
        protected BlogAiSettingRepository $settings,
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
        $targets = array_slice($this->batchService->targets($blog, AiMode::QualityDiagnosis, AiBatchTarget::NeedsReevaluation), 0, $remaining);
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
            [],
            null,
            $setting->auto_revision_enabled
                ? $this->followUpRevision($setting->auto_revision_model, $setting->auto_revision_reasoning_effort, $setting->auto_revision_scope)
                : null,
        );
    }

    /**
     * 品質診断の後に、基準（受け入れの目安の点数）に満たない記事の編集案を作る設定（D-26）。
     * 改修範囲の初期値は「点数で自動判別」（D-27-02）
     *
     * @return array{below_score: float, model: string, effort: string, revision_scope: string}
     */
    public function followUpRevision(?string $model, ?string $effort, ?string $scope = null): array
    {
        $defaults = (array) config('blogos.ai.api.defaults.revision');

        return [
            'below_score'    => (float) config('blogos.ai.acceptance_score'),
            'model'          => $model ?: $defaults['model'],
            'effort'         => $effort ?: $defaults['effort'],
            'revision_scope' => $scope ?: AiBatchService::SCOPE_BY_SCORE,
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
