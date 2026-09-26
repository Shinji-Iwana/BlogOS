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
     * BlogOSのAI機能（ARCHITECTURE 18章、D-07-03〜D-07-08）
     *
     * モデル名はコードに書かず、ここ（または .env）で指定する。
     */
    'ai' => [
        // 実行モードごとの実行方式（manual：手動実行 / api：API実行）。当面は手動実行を標準とする（D-07-06）
        'methods' => [
            'seo_analysis'      => env('BLOGOS_AI_METHOD_SEO_ANALYSIS', 'manual'),
            'structure'         => env('BLOGOS_AI_METHOD_STRUCTURE', 'manual'),
            'quality_diagnosis' => env('BLOGOS_AI_METHOD_QUALITY_DIAGNOSIS', 'manual'),
            'revision'          => env('BLOGOS_AI_METHOD_REVISION', 'manual'),
            'new_article'       => env('BLOGOS_AI_METHOD_NEW_ARTICLE', 'manual'),
            'management_suggestion' => env('BLOGOS_AI_METHOD_MANAGEMENT_SUGGESTION', 'manual'),
        ],

        // 手動実行で記録する、利用しているサービスとモデルの候補（画面で選ぶか、直接入力する。D-07-07）
        'manual' => [
            'provider'      => 'openai',
            'service_plans' => ['ChatGPT（無料）', 'ChatGPT Plus', 'ChatGPT Pro'],
            'models'        => ['gpt-6-sol', 'gpt-6-luna', 'gpt-6-astra'],
            'efforts'       => ['なし', '低', '中', '高'],
        ],

        // API実行（D-07-06、D-07-08、D-24）。APIキーは config/services.php の openai.key（.env の OPENAI_API_KEY）
        'api' => [
            'provider' => 'openai',

            // 実行モードごとの標準のモデルと推論の深さ（画面で実行ごとに変えられる）。
            // 当面は gpt-6-luna を標準にし、必要なときに gpt-6-sol を選ぶ（D-25-01）。まとめて実行の標準も、この値を使う
            'defaults' => [
                'seo_analysis'      => ['model' => env('BLOGOS_AI_MODEL_SEO_ANALYSIS', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_SEO_ANALYSIS', 'medium')],
                'structure'         => ['model' => env('BLOGOS_AI_MODEL_STRUCTURE', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_STRUCTURE', 'medium')],
                'quality_diagnosis' => ['model' => env('BLOGOS_AI_MODEL_QUALITY_DIAGNOSIS', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_QUALITY_DIAGNOSIS', 'medium')],
                'revision'          => ['model' => env('BLOGOS_AI_MODEL_REVISION', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_REVISION', 'medium')],
                'new_article'       => ['model' => env('BLOGOS_AI_MODEL_NEW_ARTICLE', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_NEW_ARTICLE', 'medium')],
                'management_suggestion' => ['model' => env('BLOGOS_AI_MODEL_MANAGEMENT_SUGGESTION', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_MANAGEMENT_SUGGESTION', 'medium')],
            ],

            // 画面で選べるモデルと、1Mトークンあたりの料金（米ドル。標準の処理。2026-09-26 に公式の料金表で確認）。
            // 料金は費用の目安と上限の判定に使う。料金が変わったらここを直す。
            // 推論の深さは、費用がかさむ xhigh・max を選べないようにしている（astra は none に対応していない）
            'models' => [
                'gpt-6-luna'  => ['input' => 0.10, 'cached_input' => 0.01, 'output' => 0.50, 'efforts' => ['none', 'low', 'medium', 'high']],
                'gpt-6-sol'   => ['input' => 2.00, 'cached_input' => 0.20, 'output' => 10.00, 'efforts' => ['none', 'low', 'medium', 'high']],
                'gpt-6-astra' => ['input' => 10.00, 'cached_input' => 1.00, 'output' => 50.00, 'efforts' => ['low', 'medium', 'high']],
            ],

            // 1回あたりの出力トークン（推論のトークンを含む）の上限。超えた場合は途中で打ち切られ、失敗になる
            'max_output_tokens' => (int) env('BLOGOS_AI_MAX_OUTPUT_TOKENS', 48000),

            // 月の費用の上限（米ドル。日本時間の月初から数える）。実行前に「今月の費用＋今回の最大の費用」がこれを超えるなら実行しない
            'monthly_budget_usd' => (float) env('BLOGOS_AI_MONTHLY_BUDGET_USD', 10),

            // 応答を待つ時間（秒）。推論が長いと数分かかる
            'timeout' => (int) env('BLOGOS_AI_TIMEOUT', 900),
        ],

        // 条件による自動の再評価（品質診断だけ。D-25）。有効・無効と、使うモデル・推論の深さは、ブログごとに画面で設定する
        'auto_reevaluation' => [
            // 設定を作るときの初期値
            'model'  => env('BLOGOS_AI_AUTO_MODEL', 'gpt-6-luna'),
            'effort' => env('BLOGOS_AI_AUTO_EFFORT', 'medium'),

            // 1日に自動で再評価する記事の上限（ブログごと）。超えた分は翌日以降に回す
            'daily_limit' => (int) env('BLOGOS_AI_AUTO_DAILY_LIMIT', 30),

            // 5. 前回の評価からこの日数が過ぎたら、定期的に見直す
            'periodic_days' => (int) env('BLOGOS_AI_AUTO_PERIODIC_DAYS', 90),

            // 4. アクセスの減少：直近の期間と、その前の同じ長さの期間を比べる（Search Console のクリック数、GA4 の表示回数）。
            // Googleの数値は数日のあいだ確定しないため、直近の数日は除く
            'traffic' => [
                'window_days'  => 28,
                'lag_days'     => 3,
                // この割合以上減ったら（0.3 = 30%）
                'drop_ratio'   => 0.3,
                // 前の期間の数値がこれ未満の記事は、ぶれが大きいため見ない
                'min_clicks'   => 20,
                'min_views'    => 50,
                // 前回の評価からこの日数が過ぎていない記事は、アクセスが落ちても再評価しない（毎日くり返さないため）
                'cooldown_days' => 28,
            ],
        ],

        // 改修範囲を点数で自動判別する場合の基準（D-27-02）。改修前の点数がこの点数以上なら、その改修範囲にする。
        // どちらにも満たなければ全面改修
        'revision_scope_by_score' => [
            'minor'       => 70,
            'restructure' => 40,
        ],

        // AIの出力を受け入れる目安の点数（人が判断する。D-07-03）
        'acceptance_score' => 90,

        // 1回の指示で実行する回数（D-07-03）
        'runs_per_instruction' => 1,
    ],

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
