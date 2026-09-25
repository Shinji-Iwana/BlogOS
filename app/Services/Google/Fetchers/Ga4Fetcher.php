<?php

namespace App\Services\Google\Fetchers;

use App\Clients\Google\GoogleApiClient;
use App\Enums\GoogleService;
use App\Models\BlogGoogleProperty;
use App\Repositories\GoogleMetricRepository;
use Illuminate\Support\Carbon;

/**
 * Google Analytics 4（Data API の runReport）。ブログ×日、ページ×日、ページ×流入元×日（D-21-02）。
 */
class Ga4Fetcher implements MetricFetcher
{
    /**
     * 1回の要求で受け取る行数の上限（GA4 Data API の上限は 250,000）
     */
    protected const PAGE_SIZE = 100000;

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
        return ['google_analytics_page_daily', 'google_analytics_page_channel_daily'];
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

        return ['rows' => $rows, 'notes' => []];
    }

    /**
     * @param array<int|string, string> $dimensions GA4の名前 => 列名（date は列名 date）
     * @param array<string, string>     $metrics    GA4の名前 => 列名
     * @return array<int, array<string, mixed>>
     */
    protected function report(GoogleApiClient $client, string $property, Carbon $from, Carbon $to, array $dimensions, array $metrics): array
    {
        $dimensionNames = array_map(fn ($key, $value) => is_int($key) ? $value : $key, array_keys($dimensions), $dimensions);
        $dimensionColumns = array_map(fn ($key, $value) => is_int($key) ? $value : $value, array_keys($dimensions), $dimensions);

        $result = [];
        $offset = 0;

        do {
            $data = $client->post(GoogleApiClient::ANALYTICS_DATA . "/{$property}:runReport", [
                'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
                'dimensions' => array_map(fn ($name) => ['name' => $name], $dimensionNames),
                'metrics'    => array_map(fn ($name) => ['name' => $name], array_keys($metrics)),
                'limit'      => self::PAGE_SIZE,
                'offset'     => $offset,
            ]);

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
