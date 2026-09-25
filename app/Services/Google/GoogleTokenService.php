<?php

namespace App\Services\Google;

use App\Clients\Google\GoogleApiClient;
use App\Clients\Google\GoogleApiException;
use App\Clients\Google\GoogleOAuthClient;
use App\Models\GoogleAccount;
use App\Repositories\GoogleAccountRepository;

/**
 * Googleアカウントのアクセストークンの管理。期限が近ければ更新用のトークンで更新してから、API Clientを返す。
 */
class GoogleTokenService
{
    /**
     * 期限のこの秒数前から、更新する
     */
    protected const REFRESH_MARGIN_SECONDS = 120;

    public function __construct(
        protected GoogleOAuthClient $oauth,
        protected GoogleAccountRepository $accounts,
    ) {
    }

    /**
     * @throws GoogleApiException
     */
    public function clientFor(GoogleAccount $account): GoogleApiClient
    {
        return new GoogleApiClient($this->accessToken($account));
    }

    /**
     * @throws GoogleApiException
     */
    public function accessToken(GoogleAccount $account): string
    {
        $valid = filled($account->access_token)
            && $account->token_expires_at !== null
            && $account->token_expires_at->isAfter(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));

        if ($valid) {
            return $account->access_token;
        }

        if (blank($account->refresh_token)) {
            $this->accounts->markError($account, '更新用のトークンがありません。Googleアカウントを接続し直してください。');

            throw new GoogleApiException('更新用のトークンがありません。Googleアカウントを接続し直してください。', 'POST', GoogleOAuthClient::TOKEN_URL);
        }

        try {
            $token = $this->oauth->refresh($account->refresh_token);
        } catch (GoogleApiException $e) {
            $this->accounts->markError($account, $e->isInvalidGrant()
                ? 'Googleアカウントの接続が無効になりました（取り消し・失効）。接続し直してください。'
                : "トークンを更新できませんでした：{$e->getMessage()}");

            throw $e;
        }

        $this->accounts->updateAccessToken($account, $token['access_token'], (int) ($token['expires_in'] ?? 3600));

        return $token['access_token'];
    }
}
