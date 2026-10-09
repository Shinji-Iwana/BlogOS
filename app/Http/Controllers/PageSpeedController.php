<?php

namespace App\Http\Controllers;

use App\Models\PageSpeedRun;
use App\Repositories\BlogRepository;
use App\Services\PageSpeed\PageSpeedService;
use App\Support\HistoryPage;

/**
 * PageSpeed Insights（D-78）
 */
class PageSpeedController extends Controller
{
    /**
     * PageSpeed Insightsとの同期履歴（メニューの「履歴」）：選択中のブログの測定の記録（新しい順）
     */
    public function runs(BlogRepository $blogRepository, PageSpeedService $service)
    {
        $blog = $blogRepository->findSelected();
        $runs = PageSpeedRun::with(['post:id,title_raw', 'page:id,title_raw'])
            ->where('blog_id', $blog?->id ?? 0)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(HistoryPage::PER_PAGE)
            ->withQueryString();

        return view('pagespeed.runs', [
            'blog'       => $blog,
            'runs'       => $runs,
            'latest'     => $blog ? PageSpeedRun::where('blog_id', $blog->id)->latest('started_at')->latest('id')->first() : null,
            'configured' => $service->isConfigured(),
        ]);
    }
}
