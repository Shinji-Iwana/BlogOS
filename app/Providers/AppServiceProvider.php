<?php

namespace App\Providers;

use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiCreditService;
use App\Services\ThemeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // artisan serve は決まった環境変数しか子プロセスへ渡さない。Windowsでは TEMP・TMP が渡らないと
        // PHPの一時フォルダが書き込めない場所になり、16KBを超える送信（AIの回答の貼り付けなど）が
        // 「Unable to create temporary file」で失われるため、渡す対象に加える（開発環境だけの問題。D-22-11）。
        if ($this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_values(array_unique([
                ...ServeCommand::$passthroughVariables, 'TEMP', 'TMP',
            ]));
        }

        // 画面のテーマ（D-49）。1回のリクエストの中では、選んだテーマを1回だけ読む
        $this->app->singleton(ThemeService::class);
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

        // 一覧のページ送り。標準の部品は Tailwind CSS 前提で、テーマに CSS がないと矢印の画像が大きく崩れるため、文字だけの部品にする
        Paginator::defaultView('partials.pagination');

        // OpenAI の残高の見込みのお知らせ（D-31-04）。表示する画面ごとに、Controller から渡さなくてよいようにする
        View::composer(['partials.ai-credit-notice', 'partials.ai-cost-line'], function ($view) {
            $view->with([
                'creditStatus'        => app(AiCreditService::class)->status(),
                'creditApiConfigured' => app(AiApiPolicy::class)->isConfigured(),
            ]);
        });
    }
}
