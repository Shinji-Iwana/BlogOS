<?php

namespace App\Services\Google;

use App\Clients\Google\GoogleApiClient;
use App\Clients\Google\GoogleApiException;
use App\Clients\Google\GoogleOAuthClient;
use App\Models\GoogleAccount;
use App\Repositories\GoogleAccountRepository;

/**
 * Googleアカウントの接続・解除と、対応先の候補の取得（D-21-01）。
 */
class GoogleConnectionService
{
    public function __construct(
        protected GoogleOAuthClient $oauth,
        protected GoogleTokenService $tokens,
        protected GoogleAccountRepository $accounts,
    ) {
    }

    /**
     * Googleのログイン画面から戻ってきたときに、トークンを受け取って保存する
     *
     * @throws GoogleApiException
     */
    public function connect(string $code, ?int $userId): GoogleAccount
    {
        $token = $this->oauth->exchangeCode($code);
        $email = $this->oauth->email($token['access_token']);

        return $this->accounts->saveFromOAuth($email, $token, $userId);
    }

    /**
     * 接続を解除する。Google側のトークンも取り消し、対応先の設定も削除する（取得済みのデータは残す）
     */
    public function disconnect(GoogleAccount $account): void
    {
        if (filled($account->refresh_token)) {
            $this->oauth->revoke($account->refresh_token);
        }

        $this->accounts->delete($account);
    }

    /**
     * 対応先の候補。サービスごとに取得し、失敗した場合はそのサービスの error に理由を入れる
     * （例：GCPのプロジェクトで、そのAPIが有効になっていない）。
     *
     * @return array<string, array{items: array<int, array{resource_name: string, display_name: string, domains?: array<int, string>}>, error: string|null}>
     */
    public function candidates(GoogleAccount $account): array
    {
        try {
            $client = $this->tokens->clientFor($account);
        } catch (GoogleApiException $e) {
            $error = ['items' => [], 'error' => $e->getMessage()];

            return ['ga4' => $error, 'search_console' => $error, 'adsense' => $error];
        }

        return [
            'ga4'            => $this->collect(fn () => $this->ga4Properties($client)),
            'search_console' => $this->collect(fn () => $this->searchConsoleSites($client)),
            'adsense'        => $this->collect(fn () => $this->adsenseAccounts($client)),
        ];
    }

    protected function collect(callable $callback): array
    {
        try {
            return ['items' => $callback(), 'error' => null];
        } catch (GoogleApiException $e) {
            return ['items' => [], 'error' => $e->getMessage()];
        }
    }

    protected function ga4Properties(GoogleApiClient $client): array
    {
        $items = [];
        $pageToken = null;

        do {
            $data = $client->get(GoogleApiClient::ANALYTICS_ADMIN . '/accountSummaries', array_filter(['pageSize' => 200, 'pageToken' => $pageToken]));

            foreach ($data['accountSummaries'] ?? [] as $summary) {
                foreach ($summary['propertySummaries'] ?? [] as $property) {
                    $items[] = [
                        'resource_name' => $property['property'],
                        'display_name'  => ($summary['displayName'] ?? '') . ' / ' . ($property['displayName'] ?? $property['property']),
                    ];
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $items;
    }

    protected function searchConsoleSites(GoogleApiClient $client): array
    {
        $data = $client->get(GoogleApiClient::SEARCH_CONSOLE . '/sites');

        return collect($data['siteEntry'] ?? [])
            ->filter(fn ($site) => ($site['permissionLevel'] ?? '') !== 'siteUnverifiedUser')
            ->map(fn ($site) => ['resource_name' => $site['siteUrl'], 'display_name' => "{$site['siteUrl']}（{$site['permissionLevel']}）"])
            ->values()
            ->all();
    }

    protected function adsenseAccounts(GoogleApiClient $client): array
    {
        $items = [];

        foreach ($client->get(GoogleApiClient::ADSENSE . '/accounts')['accounts'] ?? [] as $account) {
            $sites = $client->get(GoogleApiClient::ADSENSE . "/{$account['name']}/sites", ['pageSize' => 1000])['sites'] ?? [];

            $items[] = [
                'resource_name' => $account['name'],
                'display_name'  => ($account['displayName'] ?? '') . "（{$account['name']}）",
                'domains'       => array_values(array_filter(array_map(fn ($site) => $site['domain'] ?? null, $sites))),
            ];
        }

        return $items;
    }
}
