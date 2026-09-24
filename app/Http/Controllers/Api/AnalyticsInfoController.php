<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\GetMetadataRequest;

class AnalyticsInfoController extends Controller
{
    public function index()
    {
        $keyFilePath = storage_path('app/google/service-account.json');
        $propertyId = config('services.ga4.property_id');
        $rows = [];
        $error = null;

        $metricNames = [
            'screenPageViews',
            'activeUsers',
            'newUsers',
            'totalUsers',
            'sessions',
            'engagedSessions',
            'engagementRate',
            'averageSessionDuration',
            'userEngagementDuration',
            'bounceRate',
            'eventCount',
            'eventsPerSession',
            'screenPageViewsPerSession',
            'sessionsPerUser',
            'conversions',
        ];

        if (!file_exists($keyFilePath) || blank($propertyId)) {
            $error = 'サービスアカウント鍵またはプロパティIDが未設定です。';
        } else {
            try {
                $client = new BetaAnalyticsDataClient([
                    'credentials' => $keyFilePath,
                ]);

                $metricChunks = array_chunk($metricNames, 10);
                $dataByPage = [];

                foreach ($metricChunks as $chunk) {
                    $request = (new RunReportRequest())
                        ->setProperty("properties/{$propertyId}")
                        ->setDateRanges([
                            new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today']),
                        ])
                        ->setDimensions([new Dimension(['name' => 'pagePath'])])
                        ->setMetrics(array_map(fn ($name) => new Metric(['name' => $name]), $chunk))
                        ->setLimit(200);

                    $response = $client->runReport($request);

                    foreach ($response->getRows() as $row) {
                        $pagePath = $row->getDimensionValues()[0]->getValue();

                        if (!isset($dataByPage[$pagePath])) {
                            $dataByPage[$pagePath] = [];
                        }

                        foreach ($chunk as $i => $name) {
                            $dataByPage[$pagePath][$name] = $row->getMetricValues()[$i]->getValue();
                        }
                    }
                }

                foreach ($dataByPage as $pagePath => $metrics) {
                    $rows[] = array_merge(['page_path' => $pagePath], $metrics);
                }

                $client->close();
            } catch (\Throwable $e) {
                $error = 'データ取得に失敗しました：' . $e->getMessage();
            }
        }

        return view('api.analytics-info', [
            'rows'        => $rows,
            'metricNames' => $metricNames,
            'total'       => count($rows),
            'error'       => $error,
        ]);
    }

    public function catalog()
    {
        $keyFilePath = storage_path('app/google/service-account.json');
        $propertyId = config('services.ga4.property_id');
        $dimensions = [];
        $metrics = [];
        $error = null;

        if (!file_exists($keyFilePath) || blank($propertyId)) {
            $error = 'サービスアカウント鍵またはプロパティIDが未設定です。';
        } else {
            try {
                $client = new BetaAnalyticsDataClient([
                    'credentials' => $keyFilePath,
                ]);

                $request = (new GetMetadataRequest())
                    ->setName("properties/{$propertyId}/metadata");

                $response = $client->getMetadata($request);

                foreach ($response->getDimensions() as $dim) {
                    $dimensions[] = [
                        'api_name'    => $dim->getApiName(),
                        'ui_name'     => $dim->getUiName(),
                        'description' => $dim->getDescription(),
                        'category'    => $dim->getCategory(),
                    ];
                }

                foreach ($response->getMetrics() as $metric) {
                    $metrics[] = [
                        'api_name'    => $metric->getApiName(),
                        'ui_name'     => $metric->getUiName(),
                        'description' => $metric->getDescription(),
                        'category'    => $metric->getCategory(),
                    ];
                }

                $client->close();
            } catch (\Throwable $e) {
                $error = 'データ取得に失敗しました：' . $e->getMessage();
            }
        }

        return view('api.analytics-catalog', [
            'dimensions' => $dimensions,
            'metrics'    => $metrics,
            'error'      => $error,
        ]);
    }
}
