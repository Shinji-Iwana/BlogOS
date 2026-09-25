<?php

return [

    'theme'=>'blank',
    //'theme'=>'ironman',

    /*
     * 管理者の認証情報
     *
     * AdminUserSeeder が管理者を登録するときに使う。
     * 公開リポジトリに認証情報を残さないため、値は必ず .env に書き、
     * ソースコードには書かない（BLOGOS_DECISIONS.md D-17-01）。
     */
    'admin' => [
        'email'    => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
     * 外部との通信（HTTPS）で証明書を検証するときに使う、信頼する証明書のファイル
     *
     * ローカルPCのウイルス対策ソフト（Norton）がHTTPS通信を検査するため、
     * PHPの標準の証明書リストでは検証できない場合に、その証明書を加えたファイルを指定する。
     * 証明書の検証そのものは止めない。本番（XServer）では設定しない（BLOGOS_DECISIONS.md D-18-06）。
     */
    'http_ca_bundle' => env('HTTP_CA_BUNDLE'),

    /*
     * 画面の表示と定期実行の時刻に使うタイムゾーン
     *
     * DBにはUTCで保存する（config/app.php の timezone は UTC のまま）。
     * 画面ではこのタイムゾーンに変換して表示し、毎日の同期などの時刻もこのタイムゾーンで指定する（D-19-03）。
     */
    'display_timezone' => env('BLOGOS_DISPLAY_TIMEZONE', 'Asia/Tokyo'),

    /*
     * Googleのデータの取得（BLOGOS_DATABASE.md 12-2、D-21-07）
     */
    'google' => [
        // Googleの数値は数日のあいだ更新されるため、毎回この日数を取得し直す
        'refetch_days'   => 4,

        // 初めて取得するときに、何か月前から取得するか（Search Consoleは約16か月前までしか取得できない）
        'initial_months' => 16,
    ],

    /*
     * 同期（BLOGOS_WORDPRESS_API.md 第III部）
     */
    'sync' => [
        // 一度にこの割合を超えるデータが一覧から消えた場合は、削除として扱わず人の確認を待つ（D-09-03）
        'mass_deletion_ratio'   => 0.1,

        // ただし、消えた件数がこの件数に満たない場合は、割合に関係なく削除として扱う（D-19-01）
        'mass_deletion_minimum' => 2,
    ],

];
