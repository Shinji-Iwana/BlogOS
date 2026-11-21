<?php

namespace App\Http\Controllers\Analytics;

use App\Support\ListPage;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Services\Google\ArticlePerformanceService;
use App\Services\Quality\QualityStandardLoader;
use Illuminate\Http\Request;

/**
 * 記事の実績と次にやること（品質評価の第4層。D-47 S4）。
 */
class PerformanceController extends Controller
{
    use UsesSelectedBlog;

    public function index(Request $request, ArticlePerformanceService $service, QualityStandardLoader $loader)
    {
        $blog = $this->selectedBlog();
        $days = in_array((int) $request->query('days'), [28, 90], true) ? (int) $request->query('days') : 28;
        $action = array_key_exists((string) $request->query('action'), ArticlePerformanceService::ACTIONS) ? (string) $request->query('action') : null;

        $rows = $service->rows($blog, $days);
        // アフィリエイトのリンクが押された回数の多い順（どの記事が収益につながっているかを見る。D-91-07）
        $sort = $request->query('sort') === 'affiliate' ? 'affiliate' : null;
        if ($sort === 'affiliate') {
            usort($rows, fn ($a, $b) => [-$a['metrics']['affiliate_clicks'], -$a['metrics']['views']] <=> [-$b['metrics']['affiliate_clicks'], -$b['metrics']['views']]);
        }
        $counts = array_fill_keys(array_keys(ArticlePerformanceService::ACTIONS), 0);
        foreach ($rows as $row) {
            foreach ($row['actions'] as $key) {
                $counts[$key]++;
            }
        }

        return view('analytics.performance', [
            'blog'     => $blog,
            'days'     => $days,
            'period'   => $service->period($blog, $days),
            'rows'     => ListPage::of($action !== null ? array_values(array_filter($rows, fn ($row) => in_array($action, $row['actions'], true))) : $rows),
            'counts'   => $counts,
            'action'   => $action,
            'sort'     => $sort,
            'affiliateTotal' => array_sum(array_map(fn ($row) => $row['metrics']['affiliate_clicks'], $rows)),
            'actions'  => ArticlePerformanceService::ACTIONS,
            'standard' => $loader->load($blog->quality_profile),
        ]);
    }
}
