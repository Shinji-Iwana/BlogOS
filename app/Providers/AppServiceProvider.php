<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 関連データの遅延読み込み（N+1の原因）を、本番以外で例外にして検出する。
        // 本番では画面を止めないよう無効にする（BLOGOS_DECISIONS.md D-11-01）。
        Model::preventLazyLoading(! $this->app->isProduction());

        // 信頼する証明書のファイルが指定されている場合だけ、HTTPS通信の検証に使う（D-18-06）。
        // 検証を止めるのではなく、信頼する証明書を差し替えるだけである。
        $caBundle = config('blogos.http_ca_bundle');
        if (filled($caBundle) && is_file($caBundle)) {
            Http::globalOptions(['verify' => $caBundle]);
        }
    }
}
