<?php

namespace App\Repositories;

use App\Enums\GoogleService;
use App\Models\BlogGoogleProperty;
use App\Models\GoogleAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Googleアカウント（google_accounts）と、ブログごとの対応先（blog_google_properties）。BLOGOS_DATABASE.md 12-1。
 */
class GoogleAccountRepository
{
    /**
     * @return Collection<int, GoogleAccount>
     */
    public function all(): Collection
    {
        return GoogleAccount::orderBy('email')->get();
    }

    public function find(int $id): ?GoogleAccount
    {
        return GoogleAccount::find($id);
    }

    /**
     * OAuthで受け取ったトークンを保存する（同じメールアドレスのアカウントは上書き）。
     * Googleは2回目以降の認可で更新用のトークンを返さないことがあるため、その場合は保存済みのものを残す。
     */
    public function saveFromOAuth(string $email, array $token, ?int $userId): GoogleAccount
    {
        $account = GoogleAccount::firstOrNew(['email' => $email]);

        $account->access_token = $token['access_token'];
        $account->token_expires_at = now()->addSeconds((int) ($token['expires_in'] ?? 3600));
        if (filled($token['refresh_token'] ?? null)) {
            $account->refresh_token = $token['refresh_token'];
        }
        $account->scopes = array_values(array_filter(explode(' ', (string) ($token['scope'] ?? ''))));
        $account->connected_by = $userId;
        $account->last_refreshed_at = now();
        $account->last_error = null;
        $account->save();

        return $account;
    }

    public function updateAccessToken(GoogleAccount $account, string $accessToken, int $expiresIn): void
    {
        $account->access_token = $accessToken;
        $account->token_expires_at = Carbon::now()->addSeconds($expiresIn);
        $account->last_refreshed_at = now();
        $account->last_error = null;
        $account->save();
    }

    /**
     * $error にトークンを含めてはならない
     */
    public function markError(GoogleAccount $account, string $error): void
    {
        $account->last_error = $error;
        $account->save();
    }

    public function delete(GoogleAccount $account): void
    {
        $account->delete();
    }

    /**
     * @return Collection<string, BlogGoogleProperty> サービスの値をキーにした対応先
     */
    public function propertiesForBlog(int $blogId): Collection
    {
        return BlogGoogleProperty::with('account')
            ->where('blog_id', $blogId)
            ->get()
            ->keyBy(fn (BlogGoogleProperty $property) => $property->service->value);
    }

    public function saveProperty(int $blogId, GoogleService $service, int $accountId, string $resourceName, ?string $displayName, ?string $adsenseDomain): BlogGoogleProperty
    {
        return BlogGoogleProperty::updateOrCreate(
            ['blog_id' => $blogId, 'service' => $service],
            [
                'google_account_id' => $accountId,
                'resource_name'     => $resourceName,
                'display_name'      => $displayName,
                'adsense_domain'    => $adsenseDomain,
            ]
        );
    }

    public function removeProperty(int $blogId, GoogleService $service): void
    {
        BlogGoogleProperty::where('blog_id', $blogId)->where('service', $service)->delete();
    }
}
