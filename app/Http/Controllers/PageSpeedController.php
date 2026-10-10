<?php

namespace App\Http\Controllers;

use App\Models\PageSpeedRun;
use App\Repositories\ArticleRepository;
use App\Repositories\BlogRepository;
use App\Services\PageSpeed\PageSpeedReportService;
use App\Services\PageSpeed\PageSpeedService;
use App\Support\HistoryPage;
use Illuminate\Http\Request;

/**
 * PageSpeed Insights（D-78）
 */
class PageSpeedController extends Controller
{
    /**
     * PageSpeed Insights情報（メニューの「情報」）：選択中のブログのサイト全体のまとめ・多い問題・記事ごとの最新の結果（携帯・デスクトップを切り替える）
     */
    public function index(Request $request, BlogRepository $blogRepository, PageSpeedService $service, PageSpeedReportService $report)
    {
        $blog = $blogRepository->findSelected();
        $strategy = array_key_exists((string) $request->query('strategy'), PageSpeedRun::STRATEGIES) ? (string) $request->query('strategy') : 'mobile';
        $sort = array_key_exists((string) $request->query('sort'), PageSpeedReportService::CATEGORIES) ? (string) $request->query('sort') : 'performance_score';

        return view('pagespeed.index', [
            'blog'       => $blog,
            'strategy'   => $strategy,
            'sort'       => $sort,
            'configured' => $service->isConfigured(),
            'home'       => $blog ? $report->latestForHome($blog->id, $strategy) : null,
            'summary'    => $blog ? $report->summary($blog->id, $strategy) : null,
            'failures'   => $blog ? $report->frequentFailures($blog->id, $strategy) : [],
            'articles'   => $blog ? $report->articles($blog->id, $strategy, $sort, HistoryPage::PER_PAGE) : null,
            'latest'     => $blog ? PageSpeedRun::where('blog_id', $blog->id)->latest('started_at')->latest('id')->first() : null,
        ]);
    }

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

    /**
     * 今すぐ測定（記事の画面・PageSpeed Insights情報の画面のトップページ）：携帯・デスクトップの2回を Queue に登録する。
     * type・id がなければ、選択中のブログのトップページを測る
     */
    public function measure(Request $request, BlogRepository $blogRepository, ArticleRepository $articles, PageSpeedService $service)
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:posts,pages'],
            'id'   => ['nullable', 'integer', 'required_with:type'],
        ]);
        $blog = $blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        if (! $service->isConfigured()) {
            return back()->withErrors(['pagespeed' => 'PageSpeed Insights の APIキーが設定されていません（.env の GOOGLE_PAGESPEED_API_KEY）。']);
        }

        $target = ['blog_id' => $blog->id, 'url' => $service->homeUrl($blog), 'post_id' => null, 'page_id' => null];
        if (isset($validated['type'])) {
            $article = $articles->find($blog->id, $validated['type'], (int) $validated['id']);
            abort_if($article === null || ! filled($article->link), 404);
            $target = [
                'blog_id' => $blog->id,
                'url'     => (string) $article->link,
                'post_id' => $validated['type'] === 'posts' ? $article->id : null,
                'page_id' => $validated['type'] === 'pages' ? $article->id : null,
            ];
        }

        $service->dispatch([$target], 'manual', $request->user()?->id);

        return back()->with('status', '携帯・デスクトップの測定を Queue に登録しました（1回に 10〜30秒かかります。ほかに待っている処理があると、もっとかかります。終わったら、この画面を開き直してください）。');
    }
}
