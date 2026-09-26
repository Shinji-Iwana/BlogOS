<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Google連携の OAuth クライアント（D-21-01）
     *
     * GA4・Search Console・AdSense の読み取りを、1つの OAuth クライアントで行う。
     * 対応先（GA4のプロパティ等）は .env ではなく、ブログごとにDBに保存する（blog_google_properties）。
     * 試作で使っていた GOOGLE_ADSENSE_* の値も、そのまま読めるようにしている。
     */
    'google' => [
        'client_id'     => env('GOOGLE_OAUTH_CLIENT_ID', env('GOOGLE_ADSENSE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', env('GOOGLE_ADSENSE_CLIENT_SECRET')),
        'redirect_uri'  => env('GOOGLE_OAUTH_REDIRECT_URI', env('GOOGLE_ADSENSE_REDIRECT_URI')),
    ],

    /*
     * OpenAI API（BlogOSのAI機能のAPI実行。D-07-06、D-24）。
     * APIキーは全ブログ共通で .env に置く（ブログごとのWordPressの認証情報とは違い、DBには保存しない）。
     */
    'openai' => [
        'key'      => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

];
