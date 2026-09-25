<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleRepository;
use App\Repositories\GoogleMetricRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 分析（選択中のブログのGoogleの指標。REQUIREMENTS 7-10〜7-12）。
 *
 * 保存済みのデータ（DB）から表示し、Google APIは呼ばない。
 */
class AnalyticsController extends Controller
{
    use UsesSelectedBlog;

    public const PERIODS = [7, 28, 90, 365];

    public function __construct(
        protected GoogleMetricRepository $metrics,
        protected ArticleRepository $articles,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();

        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 28;
        [$from, $to] = self::period($days);
        $sort = in_array($request->query('sort'), ['views', 'clicks', 'impressions', 'earnings'], true) ? $request->query('sort') : 'views';

        $totals = $this->metrics->articleTotals($blog->id, $from, $to)->sortByDesc($sort)->take(100)->values();

        $articles = $this->articles->findMany($totals->pluck('post_id')->filter()->all(), $totals->pluck('page_id')->filter()->all());

        return view('analytics.index', [
            'blog'       => $blog,
            'days'       => $days,
            'from'       => $from,
            'to'         => $to,
            'sort'       => $sort,
            'site'       => $this->metrics->siteTotals($blog->id, $from, $to),
            'articles'   => $totals,
            'posts'      => $articles['posts'],
            'pages'      => $articles['pages'],
            'unresolved' => $this->metrics->unresolvedUrls($blog->id, $from, $to),
        ]);
    }

    /**
     * 直近の日数の期間（昨日まで。表示用のタイムゾーンの日付）
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function period(int $days, int $offsetDays = 0): array
    {
        $to = Carbon::parse(Carbon::now(config('blogos.display_timezone'))->subDays(1 + $offsetDays)->toDateString());

        return [$to->copy()->subDays($days - 1), $to];
    }
}
