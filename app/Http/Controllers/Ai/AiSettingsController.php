<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AiBatchTarget;
use App\Enums\AiMode;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\BlogAiSettingRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\AiException;
use App\Services\Ai\AutoReevaluationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ブログごとのAIの設定（条件による自動の再評価の有効・無効と、使うモデル。D-25）。
 */
class AiSettingsController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected BlogAiSettingRepository $settings,
        protected AiApiPolicy $apiPolicy,
        protected AiBatchService $batchService,
        protected AutoReevaluationService $autoService,
    ) {
    }

    public function edit()
    {
        $blog = $this->selectedBlog();

        return view('ai.settings.edit', [
            'blog'       => $blog,
            'setting'    => $this->settings->forBlog($blog),
            'configured' => $this->apiPolicy->isConfigured(),
            'models'     => $this->apiPolicy->models(),
            // 今日の時点で、自動の再評価の対象になる記事（有効・無効にかかわらず表示する）
            'targets'    => $this->batchService->targets($blog, AiMode::QualityDiagnosis, AiBatchTarget::NeedsReevaluation),
            'remaining'  => $this->autoService->remainingToday($blog),
            'config'     => config('blogos.ai.auto_reevaluation'),
            'revision'   => $this->autoService->followUpRevision(null, null),
            'scopeOptions' => AutoReevaluationService::scopeOptions(),
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
        ], $request->user()?->id);

        return redirect()->route('ai.settings.edit')->with('status', 'AIの設定を保存しました。' . ($enabled && ! $this->apiPolicy->isConfigured() ? '（APIキーが設定されていないため、自動の再評価は実行されません）' : ''));
    }
}
