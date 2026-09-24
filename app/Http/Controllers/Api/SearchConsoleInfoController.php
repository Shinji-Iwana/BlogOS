<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Client;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;

class SearchConsoleInfoController extends Controller
{
    public function index()
    {
        $keyFilePath = storage_path('app/google/service-account.json');
        $siteUrl = config('services.gsc.site_url');
        $rows = [];
        $appearanceRows = [];
        $error = null;

        if (!file_exists($keyFilePath) || blank($siteUrl)) {
            $error = 'サービスアカウント鍵またはサイトURLが未設定です。';
        } else {
            try {
                $client = new Client();
                $client->setAuthConfig($keyFilePath);
                $client->addScope(SearchConsole::WEBMASTERS_READONLY);

                $service = new SearchConsole($client);

                $request = new SearchAnalyticsQueryRequest([
                    'startDate'  => now()->subDays(28)->format('Y-m-d'),
                    'endDate'    => now()->format('Y-m-d'),
                    'dimensions' => ['page', 'query', 'country', 'device'],
                    'rowLimit'   => 500,
                ]);

                $response = $service->searchanalytics->query($siteUrl, $request);

                foreach ((array) $response->getRows() as $row) {
                    $keys = $row->getKeys();
                    $rows[] = [
                        'page'        => $keys[0] ?? '',
                        'query'       => $keys[1] ?? '',
                        'country'     => $keys[2] ?? '',
                        'device'      => $keys[3] ?? '',
                        'clicks'      => $row->getClicks(),
                        'impressions' => $row->getImpressions(),
                        'ctr'         => round($row->getCtr() * 100, 2),
                        'position'    => round($row->getPosition(), 1),
                    ];
                }

                $appearanceRequest = new SearchAnalyticsQueryRequest([
                    'startDate'  => now()->subDays(28)->format('Y-m-d'),
                    'endDate'    => now()->format('Y-m-d'),
                    'dimensions' => ['searchAppearance'],
                    'rowLimit'   => 500,
                ]);

                $appearanceResponse = $service->searchanalytics->query($siteUrl, $appearanceRequest);

                foreach ((array) $appearanceResponse->getRows() as $row) {
                    $keys = $row->getKeys();
                    $appearanceRows[] = [
                        'appearance'  => $keys[0] ?? '',
                        'clicks'      => $row->getClicks(),
                        'impressions' => $row->getImpressions(),
                        'ctr'         => round($row->getCtr() * 100, 2),
                        'position'    => round($row->getPosition(), 1),
                    ];
                }
            } catch (\Throwable $e) {
                $error = 'データ取得に失敗しました：' . $e->getMessage();
            }
        }

        return view('api.search-console-info', [
            'rows'           => $rows,
            'appearanceRows' => $appearanceRows,
            'total'          => count($rows),
            'appearanceTotal'=> count($appearanceRows),
            'error'          => $error,
        ]);
    }
}
