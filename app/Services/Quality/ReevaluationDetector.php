<?php

namespace App\Services\Quality;

use App\Enums\AiMode;
use App\Enums\GoogleIndexCategory;
use App\Enums\ReevaluationReason;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
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
 *
 * 並び順（1日の上限の中で、どの記事から再評価するか。D-48）：大きな問題がある記事を先にする。
 * 1段目：インデックス未登録・前回の点数が40点未満（全面改修が必要）・記事の型の★の項目が ×
 * 2段目：前回の点数が70点未満（構成の見直しが必要）・まだ評価していない ／ 3段目：それ以外
 * 同じ段の中では理由の順、その後は Search Console の表示回数の多い順（直すと効果の大きい記事から）。
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
     * 再評価が必要な記事（大きな問題がある順。D-48）
     *
     * @return list<array{article: Post|Page, evaluation: ArticleEvaluation|null, reason: ReevaluationReason, tier: int, priority_notes: list<string>, impressions: int}>
     */
    public function detect(Blog $blog): array
    {
        $standard = $this->loader->load($blog->quality_profile);
        $templateVersion = AiTemplate::load(AiMode::QualityDiagnosis)->version;
        $linkCounts = $this->articles->inboundLinkCounts($blog->id);
        [$trafficDrops, $impressions] = $this->traffic($blog);
        $notIndexed = GoogleIndexStatus::where('blog_id', $blog->id)->whereNotNull('category')
            ->whereNotIn('category', [GoogleIndexCategory::Indexed->value, GoogleIndexCategory::Unknown->value])->get(['post_id', 'page_id'])
            ->mapWithKeys(fn ($status) => [($status->post_id ? 'posts:' . $status->post_id : 'pages:' . $status->page_id) => true])->all();
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
                [$tier, $notes] = $this->severity($evaluation, isset($notIndexed["{$key}:{$article->id}"]));
                $result[] = $row + ['reason' => $reason, 'tier' => $tier, 'priority_notes' => $notes, 'impressions' => $impressions["{$key}:{$article->id}"] ?? 0];
            }
        }

        usort($result, fn ($a, $b) => [$a['tier'], $a['reason']->priority(), -$a['impressions']] <=> [$b['tier'], $b['reason']->priority(), -$b['impressions']]);

        return $result;
    }

    /**
     * 問題の大きさの段（1 が最優先）と、その理由（D-48）
     *
     * @return array{0: int, 1: list<string>}
     */
    protected function severity(?ArticleEvaluation $evaluation, bool $notIndexed): array
    {
        $notes = [];
        if ($notIndexed) {
            $notes[] = 'インデックス未登録';
        }
        if ($evaluation?->score !== null && $evaluation->score < 40) {
            $notes[] = "前回 {$evaluation->score}点（全面改修が必要）";
        }
        if (! empty($evaluation?->type_failures)) {
            $notes[] = '記事の型の必須の項目が ×';
        }
        if ($notes !== []) {
            return [1, $notes];
        }

        if ($evaluation === null) {
            // 理由（未評価）と同じため、優先の理由には書かない
            return [2, []];
        }
        if ($evaluation->score !== null && $evaluation->score < 70) {
            return [2, ["前回 {$evaluation->score}点（構成の見直しが必要）"]];
        }

        return [3, []];
    }

    /**
     * アクセスが落ちた記事（Search Console のクリック数、または GA4 の表示回数）と、今の期間の記事ごとの表示回数
     *
     * @return array{0: array{posts: array<int, true>, pages: array<int, true>}, 1: array<string, int>}
     */
    protected function traffic(Blog $blog): array
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

        $impressions = $current->map(fn ($row) => (int) $row->impressions)->all();

        return [$drops, $impressions];
    }
}
