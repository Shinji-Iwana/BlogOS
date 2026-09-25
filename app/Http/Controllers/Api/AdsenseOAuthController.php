<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Client;
use Illuminate\Http\Request;

class AdsenseOAuthController extends Controller
{
    public function redirect()
    {
        return redirect($this->makeClient()->createAuthUrl());
    }

    public function callback(Request $request)
    {
        $client = $this->makeClient();
        $token = $client->fetchAccessTokenWithAuthCode($request->query('code'));

        if (isset($token['error'])) {
            return '認証に失敗しました：' . $token['error'];
        }

        if (!is_dir(storage_path('app/google'))) {
            mkdir(storage_path('app/google'), 0755, true);
        }

        file_put_contents(storage_path('app/google/adsense-token.json'), json_encode($token));

        return '認証が完了しました。<a href="' . route('api-adsense-info') . '">AdSense情報一覧ページへ</a>';
    }

    private function makeClient(): Client
    {
        $client = new Client();
        $client->setClientId(config('services.adsense.client_id'));
        $client->setClientSecret(config('services.adsense.client_secret'));
        $client->setRedirectUri(config('services.adsense.redirect_uri'));
        $client->addScope('https://www.googleapis.com/auth/adsense.readonly');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }
}
