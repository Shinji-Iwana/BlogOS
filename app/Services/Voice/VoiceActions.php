<?php

namespace App\Services\Voice;

use App\Enums\AiBatchTarget;
use App\Enums\AiBatchTrigger;
use App\Enums\AiMode;
use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\AiException;
use App\Services\Sync\SyncDispatcher;
use App\Services\Sync\SyncStatusService;

/**
 * 音声の操作の「確認つきの操作」（D-58 段階2）。
 *
 * 操作の道具を呼んだ時点では実行せず、内容と費用の目安を「確認待ち」としてセッションに覚える（prepare）。
 * 利用者が次の発言で同意したら、AI が confirm_action を呼び、そこで実行する（confirm）。
 * 同じ発言の中での確認・期限切れ・選択中のブログが変わった後の確認は受け付けない（AI が自分で確認を済ませないように）。
 * 操作は、同期の開始と、品質診断のまとめて実行だけ。WordPress への反映・削除・承認は声ではしない。
 */
class VoiceActions
{
    protected const SESSION_KEY = 'voice.pending_action';

    /** 確認待ちの有効期限（秒） */
    public const EXPIRES_SECONDS = 90;

    /** 品質診断のまとめて実行の、1回の件数の上限 */
    public const MAX_DIAGNOSIS = 30;

    /** 声で選べる、品質診断の対象 */
    public const DIAGNOSIS_TARGETS = ['below_score', 'unevaluated', 'needs_reevaluation', 'not_indexed'];

    public function __construct(
        protected SyncDispatcher $syncDispatcher,
        protected SyncStatusService $syncStatus,
        protected AiBatchService $batches,
        protected AiApiPolicy $policy,
    ) {
    }

    /**
     * 同期の開始を、確認待ちにする
     */
    public function prepareSync(?Blog $blog, int $turnId): array
    {
        if ($blog === null) {
            return ['error' => 'ブログが選ばれていません。'];
        }
        if ($blog->isArchived()) {
            return ['error' => 'アーカイブしたブログは同期できません。'];
        }
        if (($state = $this->syncStatus->forBlog($blog)['state']) !== 'idle') {
            return ['error' => $state === 'running' ? '同期は既に実行中です。' : '同期は既に開始待ちです。'];
        }

        return $this->remember('sync', [], $blog, $turnId, "{$blog->display_name} の同期を、今すぐ始めます（費用はかかりません）。");
    }

    /**
     * 品質診断のまとめて実行を、確認待ちにする（対象の件数と費用の目安を出す）
     */
    public function prepareDiagnosis(?Blog $blog, int $turnId, string $target, ?int $belowScore, int $limit): array
    {
        if ($blog === null) {
            return ['error' => 'ブログが選ばれていません。'];
        }
        if (! in_array($target, self::DIAGNOSIS_TARGETS, true)) {
            return ['error' => "「{$target}」は対象にできません。"];
        }

        $limit = max(1, min($limit, self::MAX_DIAGNOSIS));
        $belowScore = $target === 'below_score' ? max(1, min((int) ($belowScore ?? 70), 100)) : null;
        $targets = $this->batches->targets($blog, AiMode::QualityDiagnosis, AiBatchTarget::from($target), $belowScore);
        if ($targets === []) {
            return ['error' => '対象の記事がありません。'];
        }

        $count = min(count($targets), $limit);
        $defaults = $this->policy->defaults(AiMode::QualityDiagnosis);
        $perArticle = $this->batches->averageCost(AiMode::QualityDiagnosis, $defaults['model']);
        $cost = $perArticle !== null ? sprintf('費用の目安は約%.2fドルです', $perArticle * $count) : '費用の目安は、まだ実行の記録がないため出せません';
        $label = ['below_score' => "{$belowScore}点未満の記事", 'unevaluated' => 'まだ評価していない記事', 'needs_reevaluation' => '再評価の条件に当てはまる記事', 'not_indexed' => 'インデックス未登録の記事'][$target];

        return $this->remember('diagnosis', ['target' => $target, 'below_score' => $belowScore, 'limit' => $count], $blog, $turnId,
            "{$label} {$count}件を、{$defaults['model']} で品質診断します（対象は全部で" . count($targets) . "件）。{$cost}。");
    }

    /**
     * 確認待ちの操作を実行する（利用者が、前の発言で示された内容に同意した）
     *
     * @return array{result: array<string, mixed>, navigate: string|null}
     */
    public function confirm(?Blog $blog, int $turnId, ?int $userId): array
    {
        $pending = session(self::SESSION_KEY);
        if (! is_array($pending)) {
            return ['result' => ['error' => '確認待ちの操作はありません。'], 'navigate' => null];
        }
        if ($pending['turn_id'] === $turnId) {
            return ['result' => ['error' => '確認は、内容を伝えた後の、利用者の次の発言で受け付けます。まず内容を伝えて、実行してよいか尋ねてください。'], 'navigate' => null];
        }

        session()->forget(self::SESSION_KEY);
        if ($pending['expires_at'] < now()->timestamp) {
            return ['result' => ['error' => '確認の期限が切れました。もう一度、操作を頼んでください。'], 'navigate' => null];
        }
        if ($blog === null || $pending['blog_id'] !== $blog->id) {
            return ['result' => ['error' => '選択中のブログが変わったため、実行しません。'], 'navigate' => null];
        }

        return match ($pending['name']) {
            'sync'      => $this->runSync($blog, $userId),
            'diagnosis' => $this->runDiagnosis($blog, $pending['arguments'], $userId),
            default     => ['result' => ['error' => 'その操作は実行できません。'], 'navigate' => null],
        };
    }

    public function cancel(): array
    {
        $had = session()->has(self::SESSION_KEY);
        session()->forget(self::SESSION_KEY);

        return ['cancelled' => $had];
    }

    protected function runSync(Blog $blog, ?int $userId): array
    {
        if ($this->syncStatus->forBlog($blog)['state'] !== 'idle') {
            return ['result' => ['error' => '同期は既に実行中か開始待ちです。'], 'navigate' => null];
        }
        $this->syncDispatcher->dispatch($blog->id, SyncTrigger::Manual, $userId);

        return ['result' => ['done' => '同期を開始しました。完了まで数分かかることがあります。'], 'navigate' => null];
    }

    protected function runDiagnosis(Blog $blog, array $arguments, ?int $userId): array
    {
        $target = AiBatchTarget::from($arguments['target']);
        $defaults = $this->policy->defaults(AiMode::QualityDiagnosis);

        try {
            $batch = $this->batches->start(
                $blog,
                AiMode::QualityDiagnosis,
                AiBatchTrigger::Manual,
                $target,
                array_slice($this->batches->targets($blog, AiMode::QualityDiagnosis, $target, $arguments['below_score']), 0, (int) $arguments['limit']),
                $defaults['model'],
                $defaults['effort'],
                array_filter(['below_score' => $target === AiBatchTarget::BelowScore ? (float) $arguments['below_score'] : null], fn ($value) => $value !== null),
                $userId,
            );
        } catch (AiException $e) {
            return ['result' => ['error' => $e->getMessage()], 'navigate' => null];
        }

        return ['result' => ['done' => "品質診断を {$batch->total_count}件、まとめて実行に登録しました。結果はまとめて実行の画面で確認できます。"], 'navigate' => route('ai.batches.show', ['id' => $batch->id])];
    }

    protected function remember(string $name, array $arguments, Blog $blog, int $turnId, string $summary): array
    {
        session()->put(self::SESSION_KEY, [
            'name'       => $name,
            'arguments'  => $arguments,
            'blog_id'    => $blog->id,
            'turn_id'    => $turnId,
            'expires_at' => now()->addSeconds(self::EXPIRES_SECONDS)->timestamp,
            'summary'    => $summary,
        ]);

        return ['needs_confirmation' => true, 'summary' => $summary, 'ask' => 'この内容を伝え、実行してよいか尋ねてください（実行は、利用者が次の発言で同意したときに confirm_action で行う）。'];
    }
}
