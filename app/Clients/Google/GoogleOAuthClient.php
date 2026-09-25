<?php

namespace App\Clients\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google の OAuth 2.0（認可・トークンの取得と更新・利用者情報）。D-21-01。
 *
 * クライアントIDと秘密は config/services.php の google から読む。トークンを返すだけで、保存はしない。
 */
class GoogleOAuthClient
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * 読み取りだけの権限（GA4・Search Console・AdSense）と、アカウントを識別するメールアドレス
     */
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/adsense.readonly',
    ];

    protected const TIMEOUT_SECONDS = 15;

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
    }

    /**
     * Googleのログイン画面のURL。更新用のトークンを確実に受け取るため、access_type=offline と prompt=consent を付ける
     */
    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => config('services.google.client_id'),
            'redirect_uri'           => config('services.google.redirect_uri'),
            'response_type'          => 'code',
            'scope'                  => implode(' ', self::SCOPES),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /**
     * 認可コードをトークンに交換する
     *
     * @return array{access_token: string, expires_in: int, refresh_token?: string, scope?: string}
     *
     * @throws GoogleApiException
     */
    public function exchangeCode(string $code): array
    {
        return $this->token([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('services.google.redirect_uri'),
        ]);
    }

    /**
     * @return array{access_token: string, expires_in: int, scope?: string}
     *
     * @throws GoogleApiException
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @throws GoogleApiException
     */
    public function email(string $accessToken): string
    {
        $response = $this->send('GET', self::USERINFO_URL, fn () => Http::timeout(self::TIMEOUT_SECONDS)->withToken($accessToken)->acceptJson()->get(self::USERINFO_URL));

        $email = $response->json('email');
        if (! is_string($email) || $email === '') {
            throw new GoogleApiException('Googleアカウントのメールアドレスを取得できませんでした。', 'GET', self::USERINFO_URL, $response->status(), $response->body());
        }

        return $email;
    }

    /**
     * トークンを取り消す（Googleアカウントの接続を解除するとき）。失敗しても例外にしない
     */
    public function revoke(string $token): bool
    {
        try {
            return Http::timeout(self::TIMEOUT_SECONDS)->asForm()->post(self::REVOKE_URL, ['token' => $token])->successful();
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * @throws GoogleApiException
     */
    protected function token(array $params): array
    {
        $response = $this->send('POST', self::TOKEN_URL, fn () => Http::timeout(self::TIMEOUT_SECONDS)->asForm()->acceptJson()->post(self::TOKEN_URL, $params + [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
        ]));

        $token = $response->json();
        if (! is_array($token) || blank($token['access_token'] ?? null)) {
            throw new GoogleApiException('Googleからトークンを受け取れませんでした。', 'POST', self::TOKEN_URL, $response->status(), $response->body());
        }

        return $token;
    }

    /**
     * @throws GoogleApiException
     */
    protected function send(string $method, string $url, callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new GoogleApiException("Googleに接続できませんでした：{$e->getMessage()}", $method, $url);
        }

        if ($response->failed()) {
            throw new GoogleApiException("GoogleがエラーをHTTP {$response->status()}で返しました。", $method, $url, $response->status(), $response->body());
        }

        return $response;
    }
}
