<?php

namespace App\Services\Google\Fetchers;

use App\Clients\Google\GoogleApiClient;
use App\Enums\GoogleService;
use App\Models\BlogGoogleProperty;
use App\Repositories\GoogleMetricRepository;
use App\Support\AffiliateLink;
use Illuminate\Support\Carbon;

/**
 * Google Analytics 4（Data API の runReport）。ブログ×日、ページ×日、ページ×流入元×日（D-21-02）、ページ×外部リンク×日のクリック（D-91-07）。
 */
class Ga4Fetcher implements MetricFetcher
{
    /**
     * 1回の要求で受け取る行数の上限（GA4 Data API の上限は 250,000）
     */
    protected const PAGE_SIZE = 100000;

    /**
     * 押されたリンクの class（拡張計測の link_classes を、イベント範囲のカスタムディメンションとして登録したもの。D-91-08）
     */
    public const LINK_CLASSES = 'customEvent:link_classes';

    protected const PAGE_METRICS = [
        'screenPageViews'        => 'screen_page_views',
        'activeUsers'            => 'active_users',
        'sessions'               => 'sessions',
        'engagedSessions'        => 'engaged_sessions',
        'userEngagementDuration' => 'user_engagement_duration',
    ];

    public function __construct(
        protected GoogleMetricRepository $metrics,
    ) {
    }

    public function service(): GoogleService
    {
        return GoogleService::Ga4;
    }

    public function siteTable(): string
    {
        return 'google_analytics_site_daily';
    }

    public function pageTables(): array
    {
        return ['google_analytics_page_daily', 'google_analytics_page_channel_daily', 'google_analytics_outbound_click_daily'];
    }

    public function fetch(GoogleApiClient $client, BlogGoogleProperty $property, Carbon $from, Carbon $to): array
    {
        $blogId = $property->blog_id;
        $rows = 0;

        $site = $this->report($client, $property->resource_name, $from, $to, ['date'], self::PAGE_METRICS + ['newUsers' => 'new_users']);
        $rows += $this->metrics->replaceRange('google_analytics_site_daily', $blogId, $from, $to, $site);

        $pages = $this->report($client, $property->resource_name, $from, $to, ['date', 'pagePath' => 'page_path'], self::PAGE_METRICS);
        $rows += $this->metrics->replaceRange('google_analytics_page_daily', $blogId, $from, $to, $pages);

        $channels = $this->report($client, $property->resource_name, $from, $to, ['date', 'pagePath' => 'page_path', 'sessionDefaultChannelGroup' => 'channel_group'], self::PAGE_METRICS);
        $rows += $this->metrics->replaceRange('google_analytics_page_channel_daily', $blogId, $from, $to, $channels);

        // 外部リンクのクリック（拡張計測の「離脱クリック」。イベント click）。アフィリエイトのリンクが押された回数を記事ごとに数える（D-91-07）。
        // カスタムディメンション link_classes が登録されていれば、押されたリンクの class（教材の目印 blogos-m{ID}）も受け取る（D-91-08）
        $notes = [];
        $dimensions = ['date', 'pagePath' => 'page_path', 'linkUrl' => 'link_url', 'linkDomain' => 'link_domain'];
        if ($this->hasDimension($client, $property->resource_name, self::LINK_CLASSES)) {
            $dimensions[self::LINK_CLASSES] = 'link_classes';
        } else {
            $notes[] = 'GA4 にカスタムディメンション link_classes（範囲：イベント）が登録されていないため、押されたリンクがどの教材かは、記事の教材との照合だけで決めます。';
        }
        $clicks = $this->report($client, $property->resource_name, $from, $to, $dimensions,
            ['eventCount' => 'clicks'], ['filter' => ['fieldName' => 'eventName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'click']]]);
        // class の "(not set)" と空は同じ行にまとめる（保存の一意キーが同じになるため）
        $merged = [];
        foreach ($clicks as $row) {
            if ($row['link_url'] === '' || $row['link_url'] === '(not set)') {
                continue;
            }
            $row = array_merge($row, [
                'link_url_hash' => sha1((string) $row['link_url']),
                'link_classes'  => self::classes((string) ($row['link_classes'] ?? '')),
                'program'       => AffiliateLink::programKey((string) $row['link_url']),
            ]);
            $key = implode("\n", [$row['date'], $row['page_path'], $row['link_url'], $row['link_classes']]);
            if (isset($merged[$key])) {
                $merged[$key]['clicks'] += $row['clicks'];
            } else {
                $merged[$key] = $row;
            }
        }
        $rows += $this->metrics->replaceRange('google_analytics_outbound_click_daily', $blogId, $from, $to, array_values($merged));

        return ['rows' => $rows, 'notes' => $notes];
    }

    /**
     * プロパティに、そのディメンション（カスタムディメンションを含む）があるか（Data API の getMetadata）。
     * 登録されていないディメンションを runReport に入れると、レポート全体が失敗するため、先に確かめる
     */
    protected function hasDimension(GoogleApiClient $client, string $property, string $apiName): bool
    {
        try {
            $metadata = $client->get(GoogleApiClient::ANALYTICS_DATA . "/{$property}/metadata");
        } catch (\App\Clients\Google\GoogleApiException) {
            // 確かめられないときは、class なしで取り込む（クリックの数そのものは取り込めるようにする）
            return false;
        }

        return collect($metadata['dimensions'] ?? [])->contains(fn ($dimension) => ($dimension['apiName'] ?? null) === $apiName);
    }

    /**
     * GA4 の link_classes の値を、保存する形にする（値がないときの "(not set)" は空。列の長さに合わせる）
     */
    public static function classes(string $value): string
    {
        $value = trim($value);

        return $value === '(not set)' ? '' : mb_substr($value, 0, 100);
    }

    /**
     * @param array<int|string, string> $dimensions GA4の名前 => 列名（date は列名 date）
     * @param array<string, string>     $metrics    GA4の名前 => 列名
     * @param array<string, mixed>|null $dimensionFilter GA4 の dimensionFilter（FilterExpression）
     * @return array<int, array<string, mixed>>
     */
    protected function report(GoogleApiClient $client, string $property, Carbon $from, Carbon $to, array $dimensions, array $metrics, ?array $dimensionFilter = null): array
    {
        $dimensionNames = array_map(fn ($key, $value) => is_int($key) ? $value : $key, array_keys($dimensions), $dimensions);
        $dimensionColumns = array_map(fn ($key, $value) => is_int($key) ? $value : $value, array_keys($dimensions), $dimensions);

        $result = [];
        $offset = 0;

        do {
            $data = $client->post(GoogleApiClient::ANALYTICS_DATA . "/{$property}:runReport", array_filter([
                'dateRanges'      => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
                'dimensions'      => array_map(fn ($name) => ['name' => $name], $dimensionNames),
                'metrics'         => array_map(fn ($name) => ['name' => $name], array_keys($metrics)),
                'dimensionFilter' => $dimensionFilter,
                'limit'           => self::PAGE_SIZE,
                'offset'          => $offset,
            ], fn ($value) => $value !== null));

            $pageRows = $data['rows'] ?? [];

            foreach ($pageRows as $row) {
                $values = [];
                foreach ($dimensionColumns as $i => $column) {
                    $value = $row['dimensionValues'][$i]['value'] ?? '';
                    $values[$column] = $column === 'date' ? Carbon::createFromFormat('Ymd', $value)->toDateString() : $value;
                }
                foreach (array_values($metrics) as $i => $column) {
                    $values[$column] = (int) round((float) ($row['metricValues'][$i]['value'] ?? 0));
                }
                $result[] = $values;
            }

            $offset += count($pageRows);
        } while ($pageRows !== [] && $offset < (int) ($data['rowCount'] ?? 0));

        return $result;
    }
}
