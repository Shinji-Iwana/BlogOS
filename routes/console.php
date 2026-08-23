<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 登録済みブログの基本情報をWordPress REST APIから毎日03:00に取得し、
// DBとの差分がある場合はblogsテーブルを更新するとともに、変更履歴を保存する。
// blogs:update-from-api コマンド自体の処理内容は UpdateBlogsFromApi が担当する。
Schedule::command('blogs:update-from-api')->dailyAt('03:00');
