<?php

namespace App\Http\Controllers;

use App\Enums\AffiliateLinkCheckResult;
use App\Enums\AffiliateProgramStatus;
use App\Enums\DraftState;
use App\Models\AffiliateProgram;
use App\Models\ArticleDraft;
use App\Services\Articles\InternalLinkChecker;
use App\Services\Articles\LinkSwitchService;
use App\Models\ScheduledTaskRun;
use App\Models\WordPressComponent;
use App\Support\ScheduledTasks;
use App\Repositories\AiPriceRepository;
use App\Repositories\BlogRepository;
use App\Services\Sync\SyncStatusService;
use App\Services\ThemeService;

class DashboardController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected SyncStatusService $syncStatusService,
        protected AiPriceRepository $priceRepository,
    ) {
    }

    /**
     * トップページ。見た目は選択中のテーマで表示する（D-16-01）。
     *
     * ブログが1件もない場合は、テーマ側でブログ登録へ誘導する。
     * 選択中のブログがない場合は null のまま表示し、画面上部の切り替えから選ばせる（表示のためにDBを書き換えない）。
     * 同期の結果と未解決の問題は、DBから読んで表示する（D-01-05）。
     */
    public function index()
    {
        $selectedBlog = $this->blogRepository->findSelected();

        // API実行の料金表のお知らせ（D-31-03）：確認待ちの値下がり、読み取れなかった料金、直近7日の値上がり
        $latestCheck = $this->priceRepository->latestCheck();

        return view(ThemeService::index(), [
            'blogs'        => $this->blogRepository->getAll(),
            'selectedBlog' => $selectedBlog,
            'syncStatus'   => $selectedBlog ? $this->syncStatusService->forBlog($selectedBlog) : null,
            'priceNotice'  => [
                'pending' => $this->priceRepository->pending()->count(),
                'failed'  => $latestCheck !== null && ! $latestCheck->succeeded(),
                'applied' => $this->priceRepository->appliedSince(now()->subDays(7)),
            ],
            // 公開された記事へのリンクに切り替える編集案（D-39）
            'linkSwitchDrafts' => $selectedBlog ? ArticleDraft::where('blog_id', $selectedBlog->id)->where('auto_reason', LinkSwitchService::REASON)
                ->whereIn('state', [DraftState::Editing->value, DraftState::Review->value])->count() : 0,
            // 定期実行（D-44）：前回が失敗した定期実行と、定期実行が長く動いていないこと（cron の停止の疑い）
            'scheduleNotice' => $this->scheduleNotice(),
            // 内部リンクのリンク切れ（D-42）
            'brokenLinks' => $selectedBlog ? app(InternalLinkChecker::class)->counts($selectedBlog)['broken'] : 0,
            // WordPress の更新・公開停止のプラグイン（D-38）
            'wordpressNotice' => $selectedBlog ? [
                'updates' => WordPressComponent::where('blog_id', $selectedBlog->id)->where('update_available', true)->count(),
                'closed'  => WordPressComponent::where('blog_id', $selectedBlog->id)->where('wporg_state', 'closed')->count(),
            ] : ['updates' => 0, 'closed' => 0],
            // アフィリエイトのリンクの確認で、提携終了の疑いがあるプログラムの数（D-33-09）
            'affiliateSuspects' => $selectedBlog ? AffiliateProgram::where('blog_id', $selectedBlog->id)
                ->whereIn('status', [AffiliateProgramStatus::Active, AffiliateProgramStatus::Unconfirmed])
                ->where('check_result', AffiliateLinkCheckResult::Suspect)->count() : 0,
        ]);
    }

    /**
     * @return array{failed: list<string>, stopped: bool}
     */
    protected function scheduleNotice(): array
    {
        $failed = [];
        foreach (array_keys(ScheduledTasks::TASKS) as $key) {
            $last = ScheduledTaskRun::where('task_key', $key)->latest('started_at')->latest('id')->first();
            if ($last !== null && ($last->status === 'failed' || $last->isStale())) {
                $failed[] = $last->label();
            }
        }

        // 一度でも定期実行が動いた後に、26時間以上動いていなければ、cron が止まっている疑い
        $lastScheduled = ScheduledTaskRun::where('trigger', 'scheduled')->max('started_at');

        return ['failed' => $failed, 'stopped' => $lastScheduled !== null && now()->subHours(26)->gt($lastScheduled)];
    }
}
