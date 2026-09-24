<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Client;
use Google\Service\Adsense;

class AdsenseInfoController extends Controller
{
    public function index()
    {
        $tokenPath = storage_path('app/google/adsense-token.json');
        $rows = [];
        $needsAuth = !file_exists($tokenPath);
        $error = null;

        if (!$needsAuth) {
            try {
                $client = new Client();
                $client->setClientId(config('services.adsense.client_id'));
                $client->setClientSecret(config('services.adsense.client_secret'));
                $client->setRedirectUri(config('services.adsense.redirect_uri'));
                $client->addScope('https://www.googleapis.com/auth/adsense.readonly');

                $token = json_decode(file_get_contents($tokenPath), true);
                $client->setAccessToken($token);

                if ($client->isAccessTokenExpired()) {
                    if ($client->getRefreshToken()) {
                        $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                        $newToken['refresh_token'] = $client->getRefreshToken();
                        file_put_contents($tokenPath, json_encode($newToken));
                        $client->setAccessToken($newToken);
                    } else {
                        $needsAuth = true;
                    }
                }

                if (!$needsAuth) {
                    $service = new Adsense($client);
                    $accounts = $service->accounts->listAccounts();

                    $metricNames = [
                        'ESTIMATED_EARNINGS',
                        'PAGE_VIEWS',
                        'PAGE_VIEWS_RPM',
                        'PAGE_VIEWS_CTR',
                        'IMPRESSIONS',
                        'IMPRESSIONS_RPM',
                        'IMPRESSIONS_CTR',
                        'CLICKS',
                        'COST_PER_CLICK',
                        'AD_REQUESTS',
                        'AD_REQUESTS_COVERAGE',
                        'AD_REQUESTS_CTR',
                        'MATCHED_AD_REQUESTS',
                        'MATCHED_AD_REQUESTS_CTR',
                        'INDIVIDUAL_AD_IMPRESSIONS',
                        'INDIVIDUAL_AD_IMPRESSIONS_CTR',
                        'ACTIVE_VIEW_VIEWABILITY',
                        'ACTIVE_VIEW_MEASURABILITY',
                        'ACTIVE_VIEW_TIME',
                    ];

                    foreach ($accounts->getAccounts() as $account) {
                        $report = $service->accounts_reports->generate($account->getName(), [
                            'dateRange'  => 'LAST_30_DAYS',
                            'dimensions' => ['DATE', 'COUNTRY_NAME'],
                            'metrics'    => $metricNames,
                        ]);

                        foreach ((array) $report->getRows() as $row) {
                            $cells = $row->getCells();
                            $rowData = [
                                'account' => $account->getDisplayName(),
                                'date'    => $cells[0]->getValue() ?? '',
                                'country' => $cells[1]->getValue() ?? '',
                            ];
                            foreach ($metricNames as $i => $name) {
                                $rowData[$name] = $cells[2 + $i]->getValue() ?? '';
                            }
                            $rows[] = $rowData;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $error = 'データ取得に失敗しました：' . $e->getMessage();
            }
        }

        return view('api.adsense-info', [
            'rows'        => $rows,
            'metricNames' => $metricNames ?? [],
            'total'       => count($rows),
            'needsAuth'   => $needsAuth,
            'error'       => $error,
        ]);
    }
}
