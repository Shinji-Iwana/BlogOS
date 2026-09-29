<?php

namespace App\Http\Controllers\Articles;

use App\Enums\KeywordType;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\ArticleKeyword;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\GoogleMetricRepository;
use App\Services\Articles\ArticleTitleChecker;
use Illuminate\Support\Carbon;

/**
 * タイトル・メタディスクリプションの改善の候補（D-36）。
 *
 * 公開中の記事を、ルールに合わない点（ArticleTitleChecker）と、Search Console の直近90日の指標とあわせて一覧にする。
 * 「取りこぼしのクリック」は、掲載順位ごとのクリック率の目安との差から出す（表示されているのにクリックされていない記事を先に直すため）。
 */
class ArticleTitleController extends Controller
{
    use UsesSelectedBlog;

    /**
     * 掲載順位ごとのクリック率の目安（大まかな値。取りこぼしの順位付けにだけ使う）
     */
    protected const EXPECTED_CTR = [1 => 0.28, 2 => 0.16, 3 => 0.11, 5 => 0.07, 10 => 0.03, 20 => 0.012, 100 => 0.005];

    public function index(GoogleMetricRepository $metrics, ArticleTitleChecker $checker)
    {
        $blog = $this->selectedBlog();
        $to = Carbon::parse(Carbon::now(config('blogos.display_timezone'))->subDays(2)->toDateString());
        $from = $to->copy()->subDays(89);
        $totals = $metrics->articleTotals($blog->id, $from, $to)->keyBy(fn ($row) => $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}");

        $keywords = ArticleKeyword::where('blog_id', $blog->id)->where('keyword_type', KeywordType::Main)->get(['post_id', 'page_id', 'keyword'])
            ->keyBy(fn ($row) => $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}");

        $rows = [];
        foreach ([Post::class => ['post:', 'posts'], Page::class => ['page:', 'pages']] as $modelClass => [$prefix, $type]) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->where('status', 'publish')->get(['id', 'blog_id', 'title_raw', 'meta_description_raw']) as $article) {
                $key = $prefix . $article->id;
                $metric = $totals[$key] ?? null;
                $impressions = (int) ($metric->impressions ?? 0);
                $clicks = (int) ($metric->clicks ?? 0);
                $position = $metric?->position !== null ? (float) $metric->position : null;
                $ctr = $impressions > 0 ? $clicks / $impressions : null;
                $expected = $position !== null ? $this->expectedCtr($position) : null;

                $rows[] = [
                    'article'     => $article,
                    'type'        => $type,
                    'keyword'     => $keywords[$key]->keyword ?? null,
                    'issues'      => $checker->check($blog, $article->title_raw, $article->meta_description_raw, $keywords[$key]->keyword ?? null, $article),
                    'impressions' => $impressions,
                    'clicks'      => $clicks,
                    'ctr'         => $ctr,
                    'position'    => $position,
                    'missed'      => $expected !== null ? max(0.0, $impressions * $expected - $clicks) : 0.0,
                ];
            }
        }

        // 取りこぼしのクリックが多い順、次に表示回数の多い順
        usort($rows, fn ($a, $b) => [$b['missed'], $b['impressions']] <=> [$a['missed'], $a['impressions']]);

        return view('articles.titles', [
            'blog'  => $blog,
            'rows'  => $rows,
            'from'  => $from,
            'to'    => $to,
            'withIssues' => count(array_filter($rows, fn ($row) => $row['issues'] !== [])),
        ]);
    }

    protected function expectedCtr(float $position): float
    {
        foreach (self::EXPECTED_CTR as $max => $ctr) {
            if ($position <= $max) {
                return $ctr;
            }
        }

        return 0.0;
    }
}
