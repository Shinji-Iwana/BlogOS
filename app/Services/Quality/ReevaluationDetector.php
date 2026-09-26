<?php

namespace App\Services\Quality;

use App\Enums\AiMode;
use App\Enums\ReevaluationReason;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleRepository;
use App\Repositories\GoogleMetricRepository;
use App\Services\Ai\AiTemplate;
use Illuminate\Support\Carbon;

/**
 * 公開中の記事のうち、再評価が必要な記事と、その理由を判定する（D-25-04）。
 *
 * 記事が変わっていなければ、再評価しても点数の違いはAIのぶれだけになるため、次の場合だけ再評価する。
 * まだ評価していない / 1. 記事の更新 / 2. 品質基準・テンプレートの更新 / 3. この記事へのリンクの増減 /
 * 4. アクセスの減少 / 5. 定期的な見直し。複数に当てはまる場合は、先の理由を記録する。
 */
class ReevaluationDetector
{
    public function __construct(
        protected ArticleRepository $articles,
        protected ArticleEvaluationRepository $evaluations,
        protected GoogleMetricRepository $metrics,
        protected QualityStandardLoader $loader,
    ) {
    }

    /**
     * 公開中の記事と、その最新の評価
     *
     * @return list<array{article: Post|Page, evaluation: ArticleEvaluation|null}>
     */
    public function articlesWithLatestEvaluation(Blog $blog): array
    {
        $latest = $this->evaluations->latestForArticles($blog->id);

        return $this->articles->publishedArticles($blog->id)
            ->map(fn (Post|Page $article) => [
                'article'    => $article,
                'evaluation' => $latest[$article instanceof Post ? 'posts' : 'pages'][$article->id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * 再評価が必要な記事（理由の優先順、同じ理由の中では記事の順）
     *
     * @return list<array{article: Post|Page, evaluation: ArticleEvaluation|null, reason: ReevaluationReason}>
     */
    public function detect(Blog $blog): array
    {
        $standard = $this->loader->load($blog->quality_profile);
        $templateVersion = AiTemplate::load(AiMode::QualityDiagnosis)->version;
        $linkCounts = $this->articles->inboundLinkCounts($blog->id);
        $trafficDrops = $this->trafficDrops($blog);
        $periodicDays = (int) config('blogos.ai.auto_reevaluation.periodic_days');
        $cooldownDays = (int) config('blogos.ai.auto_reevaluation.traffic.cooldown_days');

        $result = [];
        foreach ($this->articlesWithLatestEvaluation($blog) as $row) {
            ['article' => $article, 'evaluation' => $evaluation] = $row;
            $key = $article instanceof Post ? 'posts' : 'pages';

            $reason = match (true) {
                $evaluation === null => ReevaluationReason::Unevaluated,

                $evaluation->evaluated_wordpress_modified_gmt === null
                    || ($article->wordpress_modified_gmt !== null && $article->wordpress_modified_gmt->gt($evaluation->evaluated_wordpress_modified_gmt)) => ReevaluationReason::Changed,

                $evaluation->quality_common_version !== $standard->commonVersion
                    || $evaluation->quality_profile_version !== $standard->profileVersion
                    || ($evaluation->generation?->template_key === AiMode::QualityDiagnosis->value && $evaluation->generation->template_version !== $templateVersion) => ReevaluationReason::Version,

                // 評価した時点の数を記録していない評価（D-25 より前）は比べない
                $evaluation->inbound_link_count !== null
                    && $evaluation->inbound_link_count !== ($linkCounts[$key][$article->id] ?? 0) => ReevaluationReason::Links,

                isset($trafficDrops[$key][$article->id])
                    && $evaluation->created_at->lt(now()->subDays($cooldownDays)) => ReevaluationReason::Traffic,

                $evaluation->created_at->lt(now()->subDays($periodicDays)) => ReevaluationReason::Periodic,

                default => null,
            };

            if ($reason !== null) {
                $result[] = $row + ['reason' => $reason];
            }
        }

        usort($result, fn ($a, $b) => $a['reason']->priority() <=> $b['reason']->priority());

        return $result;
    }

    /**
     * アクセスが落ちた記事（Search Console のクリック数、または GA4 の表示回数）
     *
     * @return array{posts: array<int, true>, pages: array<int, true>}
     */
    protected function trafficDrops(Blog $blog): array
    {
        $config = (array) config('blogos.ai.auto_reevaluation.traffic');
        $window = (int) $config['window_days'];

        $today = Carbon::now(config('blogos.display_timezone'))->startOfDay();
        $currentTo = $today->copy()->subDays((int) $config['lag_days']);
        $currentFrom = $currentTo->copy()->subDays($window - 1);
        $previousTo = $currentFrom->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($window - 1);

        $key = fn ($row) => $row->post_id ? "posts:{$row->post_id}" : "pages:{$row->page_id}";
        $current = $this->metrics->articleTotals($blog->id, $currentFrom, $currentTo)->keyBy($key);
        $previous = $this->metrics->articleTotals($blog->id, $previousFrom, $previousTo);

        $ratio = 1 - (float) $config['drop_ratio'];
        $drops = ['posts' => [], 'pages' => []];
        foreach ($previous as $before) {
            $after = $current->get($key($before));
            $clicksDropped = $before->clicks >= $config['min_clicks'] && ($after->clicks ?? 0) <= $before->clicks * $ratio;
            $viewsDropped = $before->views >= $config['min_views'] && ($after->views ?? 0) <= $before->views * $ratio;

            if ($clicksDropped || $viewsDropped) {
                $drops[$before->post_id ? 'posts' : 'pages'][$before->post_id ?? $before->page_id] = true;
            }
        }

        return $drops;
    }
}
