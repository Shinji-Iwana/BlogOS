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

];
