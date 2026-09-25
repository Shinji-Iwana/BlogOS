<?php

namespace App\Http\Controllers\Google;

use App\Clients\Google\GoogleApiException;
use App\Clients\Google\GoogleOAuthClient;
use App\Http\Controllers\Controller;
use App\Services\Google\GoogleConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Googleアカウントの接続（OAuth。D-21-01）。
 *
 * 画面の「Googleアカウントを接続する」→ Googleのログイン画面 → callback でトークンを受け取り、暗号化して保存する。
 * 他のサイトから callback を呼ばれても接続されないよう、state を照合する。
 */
class GoogleOAuthController extends Controller
{
    protected const STATE_KEY = 'google_oauth_state';

    public function __construct(
        protected GoogleOAuthClient $oauth,
        protected GoogleConnectionService $connection,
    ) {
    }

    public function redirect(Request $request)
    {
        if (! $this->oauth->isConfigured()) {
            return redirect()->route('google.settings')->withErrors(['google' => 'GoogleのOAuthクライアント（.env の GOOGLE_OAUTH_CLIENT_ID 等）が設定されていません。']);
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->oauth->authorizationUrl($state));
    }

    public function callback(Request $request)
    {
        $expected = $request->session()->pull(self::STATE_KEY);

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('google.settings')->withErrors(['google' => '接続を確認できませんでした（state が一致しません）。もう一度お試しください。']);
        }

        if ($request->filled('error')) {
            return redirect()->route('google.settings')->withErrors(['google' => "Googleアカウントの接続が完了しませんでした（{$request->query('error')}）。"]);
        }

        try {
            $account = $this->connection->connect((string) $request->query('code'), $request->user()?->id);
        } catch (GoogleApiException $e) {
            return redirect()->route('google.settings')->withErrors(['google' => "Googleアカウントを接続できませんでした：{$e->getMessage()}"]);
        }

        $missing = array_diff(GoogleOAuthClient::SCOPES, array_merge($account->scopes ?? [], ['openid', 'email']));
        $status = "Googleアカウント（{$account->email}）を接続しました。";
        if ($missing !== []) {
            $status .= ' ただし、次の権限が許可されていません：' . implode(', ', $missing);
        }

        return redirect()->route('google.settings')->with('status', $status);
    }
}
