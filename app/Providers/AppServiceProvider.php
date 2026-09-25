<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
    }
}
