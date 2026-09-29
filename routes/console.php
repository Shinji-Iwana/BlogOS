<?php

use Illuminate\Support\Facades\Schedule;

/*
 * 定期実行（XServerのcronから `php artisan schedule:run` を毎分実行する。ARCHITECTURE 28章、D-04-07）
 *
 * Queueの処理は、cronから `php artisan queue:work --stop-when-empty --max-time=<秒数>` を
 * 定期的に起動して行う（共用サーバーでは処理プロセスを常駐できないため）。
 *
 * アプリのタイムゾーンはUTCのため、時刻は表示用のタイムゾーン（日本時間）で指定する（D-19-03）。
 */

// 毎日のWordPressとの同期（D-01-03）。アーカイブしていない全ブログを、Queueに登録する
Schedule::command('blogs:sync')->dailyAt('03:00')->timezone(config('blogos.display_timezone'));

// 毎日のGoogleのデータの取得（D-21-07）。WordPressとの同期の後に行う
Schedule::command('google:fetch')->dailyAt('05:00')->timezone(config('blogos.display_timezone'));

// WordPress 本体・プラグイン・テーマの更新の確認（D-38）
Schedule::command('wordpress:check-updates')->dailyAt('04:45')->timezone(config('blogos.display_timezone'));

// 記事のインデックスの登録状態（Search Console の URL 検査。D-37）
Schedule::command('google:inspect-index')->dailyAt('05:30')->timezone(config('blogos.display_timezone'));

// API実行の料金表を、OpenAIの公式のページと照合する（値上がりは自動で反映、値下がりは人が確認。D-31-03）
Schedule::command('ai:check-prices')->dailyAt('04:30')->timezone(config('blogos.display_timezone'));

// 条件による自動の再評価（AIの設定で有効にしたブログだけ）。同期（3:00）とGoogleの取得（5:00）の後に判定する（D-25）
Schedule::command('ai:auto-reevaluate')->dailyAt('06:00')->timezone(config('blogos.display_timezone'));

// 教材の定期チェック（AIの設定で有効にしたブログだけ）。前回の調査から期間が過ぎた教材を、1日に上限の数まで調べ直す（D-30）
Schedule::command('materials:check')->dailyAt('06:30')->timezone(config('blogos.display_timezone'));

// アフィリエイトのプログラムのリンクの確認（週1回・月曜）。提携が終わったプログラムを見つける（D-33-09）
Schedule::command('affiliate:check-links')->weeklyOn(1, '06:45')->timezone(config('blogos.display_timezone'));

// 保存期間を過ぎた同期の記録などを削除する（D-04-08。対象は Prunable を使うModel）
Schedule::command('model:prune')->dailyAt('04:00')->timezone(config('blogos.display_timezone'));
