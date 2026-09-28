<?php

namespace App\Http\Controllers;

use App\Enums\AffiliateLinkCheckResult;
use App\Enums\AffiliateProgramStatus;
use App\Models\AffiliateProgram;
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
            // アフィリエイトのリンクの確認で、提携終了の疑いがあるプログラムの数（D-33-09）
            'affiliateSuspects' => $selectedBlog ? AffiliateProgram::where('blog_id', $selectedBlog->id)
                ->whereIn('status', [AffiliateProgramStatus::Active, AffiliateProgramStatus::Unconfirmed])
                ->where('check_result', AffiliateLinkCheckResult::Suspect)->count() : 0,
        ]);
    }
}
