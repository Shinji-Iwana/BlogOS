<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AiBatchTarget;
use App\Enums\AiMode;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\AiPriceRepository;
use App\Repositories\BlogAiSettingRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\AiException;
use App\Services\Ai\AutoReevaluationService;
use App\Services\Schedule\ScheduledTaskService;
use App\Support\ScheduledTasks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ブログごとのAIの設定（条件による自動の再評価の有効・無効と、使うモデル。D-25）。
 * 設定は、メニューの「設定 → 定期実行 → 記事の再評価」のポップアップ（ai/settings/reevaluation-modal）から保存する。
 * 画面「AIの設定」は、API実行の料金表と、自動の再評価の今日の対象を出す（D-64）。
 */
class AiSettingsController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected BlogAiSettingRepository $settings,
        protected AiApiPolicy $apiPolicy,
        protected AiBatchService $batchService,
        protected AutoReevaluationService $autoService,
        protected AiPriceRepository $priceRepository,
        protected ScheduledTaskService $schedule,
    ) {
    }

    public function edit()
    {
        $blog = $this->selectedBlog();

        return view('ai.settings.edit', [
            'blog'       => $blog,
            // 今日の時点で、自動の再評価の対象になる記事（有効・無効にかかわらず表示する）
            'targets'    => $this->batchService->targets($blog, AiMode::QualityDiagnosis, AiBatchTarget::NeedsReevaluation),
            'remaining'  => $this->autoService->remainingToday($blog),
            // API実行の料金表（全ブログ共通。D-31-03）
            'priceModels'      => $this->apiPolicy->models(),
            'webSearchPrice'   => $this->apiPolicy->webSearch()['cost_per_call'],
            'imagePriceModels' => $this->apiPolicy->imageModels(),
            'storedPrices'     => $this->priceRepository->all(),
            'pendingPrices'    => $this->priceRepository->pending(),
            'priceHistory'     => $this->priceRepository->recentChanges(10),
            'latestPriceCheck' => $this->priceRepository->latestCheck(),
        ]);
    }

    public function update(Request $request)
    {
        $blog = $this->selectedBlog();

        $validated = $request->validate([
            'auto_reevaluation_enabled' => ['nullable', 'boolean'],
            'auto_model'                => ['required', 'string', 'max:100'],
            'auto_reasoning_effort'     => ['required', 'string', 'max:30'],
            'auto_revision_enabled'          => ['nullable', 'boolean'],
            'auto_revision_model'            => ['required', 'string', 'max:100'],
            'auto_revision_reasoning_effort' => ['required', 'string', 'max:30'],
            'auto_revision_scope'            => ['nullable', Rule::in(array_keys(AutoReevaluationService::scopeOptions()))],
            // 基準を満たすまで、改修と診断を繰り返す上限の回数（D-65）
            'auto_revision_max_rounds'       => ['nullable', 'integer', 'between:1,' . (int) config('blogos.ai.auto_reevaluation.max_revision_rounds')],
            // 定期実行の時刻（全ブログ共通。ポップアップから送ったときだけ。D-64）
            'frequency'                      => ['sometimes', 'required', Rule::in(['daily', 'weekly'])],
            'weekday'                        => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:0,6'],
            'time'                           => ['sometimes', 'required', 'date_format:H:i'],
        ]);

        try {
            $this->apiPolicy->assertSelectable($validated['auto_model'], $validated['auto_reasoning_effort']);
            $this->apiPolicy->assertSelectable($validated['auto_revision_model'], $validated['auto_revision_reasoning_effort']);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        $enabled = (bool) ($validated['auto_reevaluation_enabled'] ?? false);
        $this->settings->save($blog, [
            'auto_reevaluation_enabled' => $enabled,
            'auto_model'                => $validated['auto_model'],
            'auto_reasoning_effort'     => $validated['auto_reasoning_effort'],
            'auto_revision_enabled'          => (bool) ($validated['auto_revision_enabled'] ?? false),
            'auto_revision_model'            => $validated['auto_revision_model'],
            'auto_revision_reasoning_effort' => $validated['auto_revision_reasoning_effort'],
            'auto_revision_scope'            => $validated['auto_revision_scope'] ?? AiBatchService::SCOPE_BY_SCORE,
            'auto_revision_max_rounds'       => (int) ($validated['auto_revision_max_rounds'] ?? $this->settings->forBlog($blog)->auto_revision_max_rounds ?? config('blogos.ai.auto_reevaluation.default_revision_rounds')),
            // 教材の定期チェックの有効・無効は、ここでは変えない（メニューのポップアップ。D-63-03）
        ], $request->user()?->id);

        $warnings = [];
        if (isset($validated['frequency'], $validated['time'])) {
            $warnings = $this->schedule->save('ai:auto-reevaluate', $validated, $request->user()?->id);
        }

        // 開いていた画面に戻る（メニューのポップアップから保存する。D-64）
        $redirect = back()->with('status', "記事の再評価の設定を保存しました（{$blog->display_name}：" . ($enabled ? '有効' : '無効') . '）。'
            . ($enabled && ! $this->apiPolicy->isConfigured() ? '（APIキーが設定されていないため、自動の再評価は実行されません）' : ''));

        return $warnings === [] ? $redirect : $redirect->withErrors(['order' => '「' . ScheduledTasks::menuLabel('ai:auto-reevaluate') . '」：' . implode(' ', $warnings)]);
    }
}
