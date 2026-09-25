<?php

namespace App\Services\Google\Fetchers;

use App\Clients\Google\GoogleApiClient;
use App\Enums\GoogleService;
use App\Models\BlogGoogleProperty;
use App\Repositories\GoogleMetricRepository;
use Illuminate\Support\Carbon;

/**
 * Search Console（検索アナリティクス）。ブログ×日、ページ×日、ページ×検索クエリ×日（D-21-03）。
 */
class SearchConsoleFetcher implements MetricFetcher
{
    /**
     * 1回の要求で受け取る行数（Search Console の上限）
     */
    protected const PAGE_SIZE = 25000;

    public function __construct(
        protected GoogleMetricRepository $metrics,
    ) {
    }

    public function service(): GoogleService
    {
        return GoogleService::SearchConsole;
    }

    public function siteTable(): string
    {
        return 'google_search_console_site_daily';
    }

    public function pageTables(): array
    {
        return ['google_search_console_page_daily', 'google_search_console_query_daily'];
    }

    public function fetch(GoogleApiClient $client, BlogGoogleProperty $property, Carbon $from, Carbon $to): array
    {
        $blogId = $property->blog_id;
        $rows = 0;

        $rows += $this->metrics->replaceRange('google_search_console_site_daily', $blogId, $from, $to,
            $this->query($client, $property->resource_name, $from, $to, ['date']));

        $rows += $this->metrics->replaceRange('google_search_console_page_daily', $blogId, $from, $to,
            $this->query($client, $property->resource_name, $from, $to, ['date', 'page']));

        $rows += $this->metrics->replaceRange('google_search_console_query_daily', $blogId, $from, $to,
            $this->query($client, $property->resource_name, $from, $to, ['date', 'page', 'query']));

        return ['rows' => $rows, 'notes' => []];
    }

    /**
     * @param array<int, string> $dimensions date / page / query
     * @return array<int, array<string, mixed>>
     */
    protected function query(GoogleApiClient $client, string $siteUrl, Carbon $from, Carbon $to, array $dimensions): array
    {
        $columns = ['date' => 'date', 'page' => 'page_url', 'query' => 'query'];
        $url = GoogleApiClient::SEARCH_CONSOLE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';

        $result = [];
        $startRow = 0;

        do {
            $data = $client->post($url, [
                'startDate'  => $from->toDateString(),
                'endDate'    => $to->toDateString(),
                'dimensions' => $dimensions,
                'type'       => 'web',
                // 最新の数日の暫定の値も受け取る（取得のたびに直近の数日を取得し直すため、後で確定値に置き換わる）
                'dataState'  => 'all',
                'rowLimit'   => self::PAGE_SIZE,
                'startRow'   => $startRow,
            ]);

            $pageRows = $data['rows'] ?? [];

            foreach ($pageRows as $row) {
                $values = [];
                foreach ($dimensions as $i => $dimension) {
                    $values[$columns[$dimension]] = (string) ($row['keys'][$i] ?? '');
                }
                $values['clicks'] = (int) ($row['clicks'] ?? 0);
                $values['impressions'] = (int) ($row['impressions'] ?? 0);
                $values['ctr'] = round((float) ($row['ctr'] ?? 0), 6);
                $values['position'] = round((float) ($row['position'] ?? 0), 3);
                $result[] = $values;
            }

            $startRow += count($pageRows);
        } while (count($pageRows) === self::PAGE_SIZE);

        return $result;
    }
}
