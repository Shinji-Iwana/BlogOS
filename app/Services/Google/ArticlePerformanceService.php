<?php

namespace App\Services\Google;

use App\Enums\GoogleIndexCategory;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\GoogleMetricRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 記事ごとの実績と、次にやること（品質評価の第4層。D-47 S4）。
 *
 * 実績（Search Console・GA4 の数字）は点数にしない。品質評価（AI の点数・観点の適合度）と並べ、決まった規則で「次にやること」を出す。
 * 掲載順位ごとのクリック率の目安は、一般的な傾向から置いた目安であり、検索語・検索結果の形・競合で大きく変わる。
 */
class ArticlePerformanceService
{
    /**
     * 掲載順位ごとのクリック率の目安（一般的な傾向。これの半分より低ければ「クリック率が低い」とする）
     */
    public const EXPECTED_CTR = [1 => 0.28, 2 => 0.15, 3 => 0.11, 4 => 0.08, 5 => 0.07, 6 => 0.05, 7 => 0.04, 8 => 0.035, 9 => 0.03, 10 => 0.025];

    /**
     * クリック率を判断する、期間の表示回数の下限（少ないと、たまたまの差が大きいため）
     */
    public const MIN_IMPRESSIONS = 100;

    public const ACTIONS = [
        'not_indexed'   => ['label' => 'インデックス未登録', 'advice' => '内容と内部リンクを改善し、Search Console で「インデックス登録をリクエスト」する', 'order' => 1],
        'low_ctr'       => ['label' => 'クリック率が低い', 'advice' => 'タイトル・メタディスクリプションを、検索した人が選びたくなる内容に直す', 'order' => 2],
        'ctr_gap'       => ['label' => '評価は高いのにクリック率が低い', 'advice' => '検索結果（上位の記事・検索意図）を見直す。タイトルの約束と検索意図がずれていないか確かめる', 'order' => 3],
        'near_top'      => ['label' => 'あと一歩で1ページ目（11〜20位）', 'advice' => '内容を強化する（検索意図・網羅性・記事の型の不足を直す）', 'order' => 4],
        'low_engagement' => ['label' => '読まれずに離脱している', 'advice' => '導入・構成・読みやすさを見直す（結論を先に、長い段落を分ける）', 'order' => 5],
        'no_impressions' => ['label' => '検索結果にほとんど表示されない', 'advice' => 'メインキーワード・検索意図（管理情報）を見直す', 'order' => 6],
        'dropping'      => ['label' => 'クリックが減っている', 'advice' => '前の期間と比べて検索語・順位の変化を確かめ、内容を最新にする', 'order' => 7],
    ];

    public function __construct(
        protected GoogleMetricRepository $metrics,
        protected ArticleEvaluationRepository $evaluations,
    ) {
    }

    /**
     * 集計の期間（Search Console のデータがある最後の日まで。前の期間は同じ長さ）
     *
     * @return array{from: Carbon, to: Carbon, previous_from: Carbon, previous_to: Carbon}|null
     */
    public function period(Blog $blog, int $days): ?array
    {
        $to = $this->metrics->lastDate('google_search_console_page_daily', $blog->id) ?? $this->metrics->lastDate('google_analytics_page_daily', $blog->id);
        if ($to === null) {
            return null;
        }
        $from = $to->copy()->subDays($days - 1);

        return ['from' => $from, 'to' => $to, 'previous_from' => $from->copy()->subDays($days), 'previous_to' => $from->copy()->subDay()];
    }

    /**
     * 公開中の記事ごとの実績・評価・次にやること
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Blog $blog, int $days = 28): array
    {
        $period = $this->period($blog, $days);
        if ($period === null) {
            return [];
        }

        $current = $this->totals($blog->id, $period['from'], $period['to']);
        $previous = $this->totals($blog->id, $period['previous_from'], $period['previous_to']);
        $evaluations = $this->evaluations->latestForArticles($blog->id);
        $index = GoogleIndexStatus::where('blog_id', $blog->id)->get(['post_id', 'page_id', 'category'])
            ->keyBy(fn ($status) => $status->post_id ? "post:{$status->post_id}" : "page:{$status->page_id}");

        $rows = [];
        foreach ([Post::class => 'post', Page::class => 'page'] as $modelClass => $type) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->where('status', 'publish')->get(['id', 'title_raw', 'link']) as $article) {
                $key = "{$type}:{$article->id}";
                $metrics = $current[$key] ?? $this->empty();
                $before = $previous[$key] ?? $this->empty();
                $evaluation = $evaluations[$type === 'post' ? 'posts' : 'pages'][$article->id] ?? null;
                $row = [
                    'article'    => $article,
                    'type'       => $type,
                    'metrics'    => $metrics,
                    'previous'   => $before,
                    'evaluation' => $evaluation,
                    'index'      => $index->get($key)?->category,
                ];
                $row['actions'] = $this->actions($row);
                $rows[] = $row;
            }
        }

        // 次にやることがある記事を先に、その中では表示回数の多い順
        usort($rows, fn ($a, $b) => [$a['actions'] === [] ? 1 : 0, -$a['metrics']['impressions'], -$a['metrics']['views']]
            <=> [$b['actions'] === [] ? 1 : 0, -$b['metrics']['impressions'], -$b['metrics']['views']]);

        return $rows;
    }

    /**
     * 次にやること（規則で決める）
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public function actions(array $row): array
    {
        $m = $row['metrics'];
        $actions = [];

        if ($row['index'] !== null && $row['index'] !== GoogleIndexCategory::Indexed && $row['index'] !== GoogleIndexCategory::Unknown) {
            $actions[] = 'not_indexed';
        }
        if ($m['impressions'] >= self::MIN_IMPRESSIONS && $m['position'] !== null && $m['ctr'] < self::expectedCtr($m['position']) / 2) {
            $ctrAxis = $row['evaluation']?->axis_scores['ctr'] ?? null;
            $actions[] = $ctrAxis !== null && $ctrAxis >= 90 ? 'ctr_gap' : 'low_ctr';
        }
        if ($m['impressions'] >= 50 && $m['position'] !== null && $m['position'] > 10 && $m['position'] <= 20) {
            $actions[] = 'near_top';
        }
        if ($m['sessions'] >= 30 && $m['engagement_rate'] !== null && $m['engagement_rate'] < 0.4) {
            $actions[] = 'low_engagement';
        }
        if ($row['index'] === GoogleIndexCategory::Indexed && $m['impressions'] < 10) {
            $actions[] = 'no_impressions';
        }
        if ($row['previous']['clicks'] >= 10 && $m['clicks'] < $row['previous']['clicks'] * 0.7) {
            $actions[] = 'dropping';
        }

        return $actions;
    }

    public static function expectedCtr(float $position): float
    {
        $rank = max(1, (int) round($position));

        return self::EXPECTED_CTR[$rank] ?? ($rank <= 20 ? 0.01 : 0.005);
    }

    /**
     * @return array<string, array<string, mixed>> post:ID / page:ID => 指標
     */
    protected function totals(int $blogId, Carbon $from, Carbon $to): array
    {
        $result = [];
        foreach ($this->metrics->articleTotals($blogId, $from, $to) as $row) {
            $key = $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}";
            $impressions = (int) $row->impressions;
            $result[$key] = [
                'views'       => (int) $row->views,
                'clicks'      => (int) $row->clicks,
                'impressions' => $impressions,
                'ctr'         => $impressions > 0 ? $row->clicks / $impressions : 0.0,
                'position'    => $row->position !== null ? round((float) $row->position, 1) : null,
                'sessions'    => 0,
                'engagement_rate' => null,
            ];
        }

        // GA4 のエンゲージメント（エンゲージのあったセッションの割合）
        $range = [$from->toDateString(), $to->toDateString()];
        $engagement = DB::table('google_analytics_page_daily')->where('blog_id', $blogId)->whereBetween('date', $range)
            ->where(fn ($q) => $q->whereNotNull('post_id')->orWhereNotNull('page_id'))
            ->groupBy('post_id', 'page_id')->selectRaw('post_id, page_id, sum(sessions) as sessions, sum(engaged_sessions) as engaged')->get();
        foreach ($engagement as $row) {
            $key = $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}";
            $result[$key] ??= $this->empty();
            $result[$key]['sessions'] = (int) $row->sessions;
            $result[$key]['engagement_rate'] = $row->sessions > 0 ? $row->engaged / $row->sessions : null;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function empty(): array
    {
        return ['views' => 0, 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => null, 'sessions' => 0, 'engagement_rate' => null];
    }
}
