<?php

namespace App\Services\PageSpeed;

use App\Clients\Google\PageSpeedClient;
use App\Clients\Google\PageSpeedException;
use App\Jobs\MeasurePageSpeedJob;
use App\Models\Blog;
use App\Models\Page;
use App\Models\PageSpeedResponse;
use App\Models\PageSpeedRun;
use App\Models\Post;
use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PageSpeed Insights の測定（D-78）。
 *
 * 定期実行（pagespeed:measure）は、測る URL を選び、URL×携帯・デスクトップごとに Queue（config の blogos.pagespeed.queue）に登録する。
 * 1回で測る URL は上限まで（まだ測っていない記事 → 前回の測定の後に更新された記事 → 前回の測定が古い記事の順）。トップページは毎回測る。
 * 測った結果は、点数・主な値・合格しなかった項目を履歴（pagespeed_runs）に残し、応答の全部は最新の1回だけ（pagespeed_responses）に残す。
 */
class PageSpeedService
{
    /** Lighthouse で合格とみなす点数（0〜1）。これより低い項目を「合格しなかった項目」として残す */
    public const PASS_AUDIT_SCORE = 0.9;

    /** 合格しなかった項目に数えない種類（点数がない・人が見る・参考の情報） */
    protected const IGNORED_DISPLAY_MODES = ['notApplicable', 'manual', 'informative', 'error'];

    /** 区分（応答のキー → 列） */
    protected const CATEGORY_COLUMNS = [
        'performance'    => 'performance_score',
        'accessibility'  => 'accessibility_score',
        'best-practices' => 'best_practices_score',
        'seo'            => 'seo_score',
    ];

    /** 試しに開いた値（応答の audits のキー → 列） */
    protected const LAB_COLUMNS = [
        'first-contentful-paint'   => 'fcp_ms',
        'largest-contentful-paint' => 'lcp_ms',
        'total-blocking-time'      => 'tbt_ms',
        'speed-index'              => 'si_ms',
        'cumulative-layout-shift'  => 'cls',
    ];

    public function __construct(
        protected ScheduledTaskService $recorder,
    ) {
    }

    public function client(): PageSpeedClient
    {
        return PageSpeedClient::fromConfig();
    }

    public function isConfigured(): bool
    {
        return $this->client()->isConfigured();
    }

    /**
     * 測る順の URL（全ブログ）。トップページ（毎回）の後に、記事を上限まで
     *
     * @param  Collection<int, Blog>  $blogs
     * @return list<array{blog_id: int, url: string, post_id: int|null, page_id: int|null}>
     */
    public function due(Collection $blogs, int $limit): array
    {
        $homes = [];
        $articles = [];
        foreach ($blogs as $blog) {
            $homes[] = ['blog_id' => $blog->id, 'url' => $this->homeUrl($blog), 'post_id' => null, 'page_id' => null];

            // 記事ごとの、最後に測った日時（携帯で成功したもの）
            $measured = PageSpeedRun::where('blog_id', $blog->id)->where('strategy', 'mobile')->where('status', 'succeeded')
                ->selectRaw('post_id, page_id, MAX(started_at) as measured_at')->groupBy('post_id', 'page_id')->get()
                ->keyBy(fn ($row) => $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}");

            foreach ([Post::class => 'post', Page::class => 'page'] as $modelClass => $type) {
                foreach ($modelClass::where('blog_id', $blog->id)->existing()->where('status', 'publish')->whereNotNull('link')->get(['id', 'link', 'wordpress_modified_gmt']) as $article) {
                    $at = $measured["{$type}:{$article->id}"]->measured_at ?? null;
                    $at = $at !== null ? Carbon::parse($at) : null;
                    $priority = match (true) {
                        $at === null                                                                         => 0,
                        $article->wordpress_modified_gmt !== null && $article->wordpress_modified_gmt->gt($at) => 1,
                        default                                                                              => 2,
                    };
                    $articles[] = [$priority, $at?->timestamp ?? 0, [
                        'blog_id' => $blog->id,
                        'url'     => (string) $article->link,
                        'post_id' => $type === 'post' ? $article->id : null,
                        'page_id' => $type === 'page' ? $article->id : null,
                    ]];
                }
            }
        }

        usort($articles, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_merge($homes, array_column(array_slice($articles, 0, max(0, $limit)), 2));
    }

    /**
     * 測る URL を、携帯・デスクトップごとに Queue に登録する（定期実行・即時実行のコマンドから）。登録した件数を返す
     *
     * @param  list<array{blog_id: int, url: string, post_id: int|null, page_id: int|null}>  $targets
     */
    public function dispatch(array $targets, string $trigger, ?int $userId = null): int
    {
        $count = 0;
        foreach ($targets as $target) {
            foreach (PageSpeedClient::STRATEGIES as $strategy) {
                // 定期実行の記録は、登録した測定が全て終わるまで閉じない
                $this->recorder->addPendingJob();
                MeasurePageSpeedJob::dispatch($target['blog_id'], $target['url'], $target['post_id'], $target['page_id'], $strategy, $trigger, $userId)
                    ->onQueue((string) config('blogos.pagespeed.queue', 'pagespeed'));
                $count++;
            }
        }

        return $count;
    }

    /**
     * 1つの URL を1回測り、記録する。上限を超えた（HTTP 429）ときは、待ってから1回だけやり直す
     */
    public function measure(int $blogId, string $url, ?int $postId, ?int $pageId, string $strategy, string $trigger, ?int $userId = null): PageSpeedRun
    {
        $run = PageSpeedRun::create([
            'blog_id'      => $blogId,
            'post_id'      => $postId,
            'page_id'      => $pageId,
            'url'          => $url,
            'strategy'     => $strategy,
            'trigger'      => $trigger,
            'status'       => 'running',
            'requested_by' => $userId,
            'started_at'   => now(),
        ]);

        $client = $this->client();
        try {
            try {
                $response = $client->run($url, $strategy);
            } catch (PageSpeedException $e) {
                if ($e->status !== 429) {
                    throw $e;
                }
                sleep((int) config('blogos.pagespeed.retry_after', 60));
                $response = $client->run($url, $strategy);
            }
        } catch (PageSpeedException $e) {
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);

            return $run;
        }

        $run->update($this->summary($response) + ['status' => 'succeeded', 'finished_at' => now()]);

        PageSpeedResponse::updateOrCreate(
            ['url_hash' => hash('sha256', $url), 'strategy' => $strategy],
            ['blog_id' => $blogId, 'url' => $url, 'pagespeed_run_id' => $run->id, 'response' => PageSpeedResponse::pack($response), 'fetched_at' => now()],
        );

        return $run;
    }

    /**
     * 応答から、履歴に残す値（点数・主な値・実際の利用者の値・合格しなかった項目）を取り出す
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function summary(array $response): array
    {
        $lighthouse = (array) ($response['lighthouseResult'] ?? []);
        $categories = (array) ($lighthouse['categories'] ?? []);
        $audits = (array) ($lighthouse['audits'] ?? []);

        $values = ['lighthouse_version' => $lighthouse['lighthouseVersion'] ?? null];
        foreach (self::CATEGORY_COLUMNS as $key => $column) {
            $score = $categories[$key]['score'] ?? null;
            $values[$column] = is_numeric($score) ? (int) round($score * 100) : null;
        }
        foreach (self::LAB_COLUMNS as $key => $column) {
            $value = $audits[$key]['numericValue'] ?? null;
            $values[$column] = is_numeric($value) ? ($column === 'cls' ? round((float) $value, 3) : (int) round($value)) : null;
        }
        foreach (['field' => 'loadingExperience', 'origin' => 'originLoadingExperience'] as $prefix => $key) {
            $values += $this->field($prefix, (array) ($response[$key] ?? []));
        }
        $values['failed_audits'] = $this->failedAudits($categories, $audits) ?: null;

        return $values;
    }

    /**
     * 実際の利用者の値（75パーセンタイル）。CLS の値は100倍で返るため、100で割る
     *
     * @param  array<string, mixed>  $experience
     * @return array<string, mixed>
     */
    protected function field(string $prefix, array $experience): array
    {
        $metrics = (array) ($experience['metrics'] ?? []);
        $percentile = fn (string $key) => is_numeric($metrics[$key]['percentile'] ?? null) ? $metrics[$key]['percentile'] : null;
        $cls = $percentile('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        return [
            "{$prefix}_lcp_ms"   => $percentile('LARGEST_CONTENTFUL_PAINT_MS') !== null ? (int) $percentile('LARGEST_CONTENTFUL_PAINT_MS') : null,
            "{$prefix}_inp_ms"   => $percentile('INTERACTION_TO_NEXT_PAINT') !== null ? (int) $percentile('INTERACTION_TO_NEXT_PAINT') : null,
            "{$prefix}_cls"      => $cls !== null ? round($cls / 100, 3) : null,
            "{$prefix}_category" => isset($experience['overall_category']) ? mb_substr((string) $experience['overall_category'], 0, 20) : null,
        ];
    }

    /**
     * 合格しなかった診断の項目（点数が合格に届かないもの。区分ごとの順）
     *
     * @param  array<string, mixed>  $categories
     * @param  array<string, mixed>  $audits
     * @return list<array{id: string, categories: list<string>, title: string, score: float, display_value: string|null}>
     */
    protected function failedAudits(array $categories, array $audits): array
    {
        $failed = [];
        foreach (array_keys(self::CATEGORY_COLUMNS) as $category) {
            foreach ((array) ($categories[$category]['auditRefs'] ?? []) as $ref) {
                $id = (string) ($ref['id'] ?? '');
                $audit = (array) ($audits[$id] ?? []);
                $score = $audit['score'] ?? null;
                if ($id === '' || ! is_numeric($score) || $score >= self::PASS_AUDIT_SCORE || in_array($audit['scoreDisplayMode'] ?? '', self::IGNORED_DISPLAY_MODES, true)) {
                    continue;
                }
                if (isset($failed[$id])) {
                    $failed[$id]['categories'][] = $category;

                    continue;
                }
                $failed[$id] = [
                    'id'            => $id,
                    'categories'    => [$category],
                    'title'         => mb_substr((string) ($audit['title'] ?? $id), 0, 300),
                    'score'         => round((float) $score, 2),
                    'display_value' => isset($audit['displayValue']) ? mb_substr((string) $audit['displayValue'], 0, 200) : null,
                ];
            }
        }

        return array_values($failed);
    }

    /**
     * ブログのトップページの URL（home。末尾の / をそろえる）
     */
    public function homeUrl(Blog $blog): string
    {
        return rtrim((string) $blog->home, '/') . '/';
    }
}
