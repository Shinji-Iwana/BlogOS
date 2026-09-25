<?php

namespace App\Services\Google\Fetchers;

use App\Clients\Google\GoogleApiClient;
use App\Clients\Google\GoogleApiException;
use App\Enums\GoogleService;
use App\Models\BlogGoogleProperty;
use App\Repositories\GoogleMetricRepository;
use Illuminate\Support\Carbon;

/**
 * AdSense（Management API v2 の reports:generate）。ブログ（ドメイン）×日と、ページ×日（D-21-04）。
 *
 * ページ単位の集計をAPIが受け付けない場合（400）は、ページ×日を保存せず、実行記録の補足に残す。
 */
class AdsenseFetcher implements MetricFetcher
{
    protected const METRICS = [
        'ESTIMATED_EARNINGS' => 'estimated_earnings',
        'PAGE_VIEWS'         => 'page_views',
        'IMPRESSIONS'        => 'impressions',
        'CLICKS'             => 'clicks',
    ];

    /**
     * ドメインで絞り込むときのディメンション
     */
    public const DOMAIN_DIMENSION = 'DOMAIN_NAME';

    /**
     * ページ単位の集計のディメンション
     */
    public const PAGE_DIMENSION = 'PAGE_URL';

    public function __construct(
        protected GoogleMetricRepository $metrics,
    ) {
    }

    public function service(): GoogleService
    {
        return GoogleService::Adsense;
    }

    public function siteTable(): string
    {
        return 'google_adsense_site_daily';
    }

    public function pageTables(): array
    {
        return ['google_adsense_page_daily'];
    }

    public function fetch(GoogleApiClient $client, BlogGoogleProperty $property, Carbon $from, Carbon $to): array
    {
        $blogId = $property->blog_id;
        $rows = 0;
        $notes = [];

        $site = $this->generate($client, $property, $from, $to, ['DATE' => 'date']);
        $rows += $this->metrics->replaceRange('google_adsense_site_daily', $blogId, $from, $to, $site);

        try {
            $pages = $this->generate($client, $property, $from, $to, ['DATE' => 'date', self::PAGE_DIMENSION => 'page_url']);
            $rows += $this->metrics->replaceRange('google_adsense_page_daily', $blogId, $from, $to, $pages);
        } catch (GoogleApiException $e) {
            if ($e->status !== 400) {
                throw $e;
            }
            $notes[] = 'AdSense APIがページ単位の集計を受け付けなかったため、ページ×日は保存していません。';
        }

        return ['rows' => $rows, 'notes' => $notes];
    }

    /**
     * @param array<string, string> $dimensions AdSenseの名前 => 列名
     * @return array<int, array<string, mixed>>
     */
    protected function generate(GoogleApiClient $client, BlogGoogleProperty $property, Carbon $from, Carbon $to, array $dimensions): array
    {
        // 同じ名前の値を繰り返すため、クエリ文字列を組み立てる
        $params = [
            'dateRange=CUSTOM',
            "startDate.year={$from->year}", "startDate.month={$from->month}", "startDate.day={$from->day}",
            "endDate.year={$to->year}", "endDate.month={$to->month}", "endDate.day={$to->day}",
            'reportingTimeZone=ACCOUNT_TIME_ZONE',
        ];
        foreach (array_keys($dimensions) as $dimension) {
            $params[] = 'dimensions=' . $dimension;
        }
        foreach (array_keys(self::METRICS) as $metric) {
            $params[] = 'metrics=' . $metric;
        }
        if (filled($property->adsense_domain)) {
            $params[] = 'filters=' . rawurlencode(self::DOMAIN_DIMENSION . '==' . $property->adsense_domain);
        }

        $data = $client->get(GoogleApiClient::ADSENSE . "/{$property->resource_name}/reports:generate", implode('&', $params));

        $headers = array_map(fn ($header) => $header['name'] ?? '', $data['headers'] ?? []);
        $currency = collect($data['headers'] ?? [])->firstWhere('name', 'ESTIMATED_EARNINGS')['currencyCode'] ?? null;
        $columns = $dimensions + self::METRICS;

        $result = [];
        foreach ($data['rows'] ?? [] as $row) {
            $values = ['currency_code' => $currency];
            foreach ($headers as $i => $name) {
                if (! isset($columns[$name])) {
                    continue;
                }
                $value = $row['cells'][$i]['value'] ?? null;
                $values[$columns[$name]] = match ($columns[$name]) {
                    'date'               => Carbon::parse($value)->toDateString(),
                    'page_url'           => (string) $value,
                    'estimated_earnings' => round((float) $value, 4),
                    default              => (int) $value,
                };
            }
            $result[] = $values;
        }

        return $result;
    }
}
