<?php

namespace App\Services\Google;

use App\DTO\Google\AnalyticsPageData;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunReportRequest;

class AnalyticsService
{
    protected array $metricNames = [
        'screenPageViews', 'activeUsers', 'newUsers', 'totalUsers', 'sessions',
        'engagedSessions', 'engagementRate', 'averageSessionDuration',
        'userEngagementDuration', 'bounceRate', 'eventCount', 'eventsPerSession',
        'screenPageViewsPerSession', 'sessionsPerUser', 'conversions',
    ];

    public function __construct(
        protected GoogleServiceAccountClient $client
    ) {
    }

    public function getMetricNames(): array
    {
        return $this->metricNames;
    }

    /**
     * @return AnalyticsPageData[]
     */
    public function getPageReport(): array
    {
        $propertyId = config('services.ga4.property_id');

        if (!$this->client->isConfigured() || blank($propertyId)) {
            throw new \RuntimeException('サービスアカウント鍵またはプロパティIDが未設定です。');
        }

        $gaClient = new BetaAnalyticsDataClient([
            'credentials' => $this->client->getKeyFilePath(),
        ]);

        $dataByPage = [];

        // GA4は1リクエストにつきメトリクス最大10個の制限があるため分割
        foreach (array_chunk($this->metricNames, 10) as $chunk) {
            $request = (new RunReportRequest())
                ->setProperty("properties/{$propertyId}")
                ->setDateRanges([new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today'])])
                ->setDimensions([new Dimension(['name' => 'pagePath'])])
                ->setMetrics(array_map(fn ($name) => new Metric(['name' => $name]), $chunk))
                ->setLimit(200);

            $response = $gaClient->runReport($request);

            foreach ($response->getRows() as $row) {
                $pagePath = $row->getDimensionValues()[0]->getValue();
                $dataByPage[$pagePath] ??= [];

                foreach ($chunk as $i => $name) {
                    $dataByPage[$pagePath][$name] = $row->getMetricValues()[$i]->getValue();
                }
            }
        }

        $gaClient->close();

        return array_map(
            fn ($pagePath, $metrics) => new AnalyticsPageData($pagePath, $metrics),
            array_keys($dataByPage),
            $dataByPage
        );
    }
}
