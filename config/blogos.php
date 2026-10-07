<?php

return [

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
            // 教材の調査・候補探し・記事の教材の見直し（D-30）。調査・候補探しは、Web検索を使えるAPI実行を標準にする
            'material_research'  => env('BLOGOS_AI_METHOD_MATERIAL_RESEARCH', 'api'),
            'material_discovery' => env('BLOGOS_AI_METHOD_MATERIAL_DISCOVERY', 'api'),
            'material_review'    => env('BLOGOS_AI_METHOD_MATERIAL_REVIEW', 'manual'),
            // 図の作成（D-32）
            'image_design'       => env('BLOGOS_AI_METHOD_IMAGE_DESIGN', 'manual'),
            // 記事の企画（D-40）。Web検索を使えるAPI実行を標準にする
            'topic_planning'     => env('BLOGOS_AI_METHOD_TOPIC_PLANNING', 'api'),
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
                'material_research'  => ['model' => env('BLOGOS_AI_MODEL_MATERIAL_RESEARCH', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_MATERIAL_RESEARCH', 'medium')],
                'material_discovery' => ['model' => env('BLOGOS_AI_MODEL_MATERIAL_DISCOVERY', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_MATERIAL_DISCOVERY', 'medium')],
                'material_review'    => ['model' => env('BLOGOS_AI_MODEL_MATERIAL_REVIEW', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_MATERIAL_REVIEW', 'medium')],
                'image_design'       => ['model' => env('BLOGOS_AI_MODEL_IMAGE_DESIGN', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_IMAGE_DESIGN', 'medium')],
                'topic_planning'     => ['model' => env('BLOGOS_AI_MODEL_TOPIC_PLANNING', 'gpt-6-luna'), 'effort' => env('BLOGOS_AI_EFFORT_TOPIC_PLANNING', 'medium')],
            ],

            // 画面で選べるモデルと、1Mトークンあたりの料金（米ドル。標準の処理。2026-09-28 に公式のモデルのページで確認）。
            // 料金は費用の目安と上限の判定に使う。料金はDBの料金表（ai_prices）を使い、毎日OpenAIの公式のページと照合する（D-31-03）。
            // ここの料金は、料金表を作るときの初期値と、料金表にないモデルの値。
            // cache_write：キャッシュの書き込み（キャッシュされていない入力は、OpenAIが自動でキャッシュに書き込み、入力の1.25倍の料金になる。D-31-01）
            // 推論の深さは、費用がかさむ xhigh・max を選べないようにしている（astra は none に対応していない）
            'models' => [
                'gpt-6-luna'  => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write' => 0.125, 'output' => 0.50, 'efforts' => ['none', 'low', 'medium', 'high']],
                'gpt-6-sol'   => ['input' => 2.00, 'cached_input' => 0.20, 'cache_write' => 2.50, 'output' => 10.00, 'efforts' => ['none', 'low', 'medium', 'high']],
                'gpt-6-astra' => ['input' => 10.00, 'cached_input' => 1.00, 'cache_write' => 12.50, 'output' => 50.00, 'efforts' => ['low', 'medium', 'high']],
            ],

            // 長い入力：1回の入力がこのトークン数を超えたら、その1回すべてを、入力・キャッシュは2倍、出力は1.5倍の料金で計算する（D-31-01）
            'long_context' => [
                'threshold_tokens'  => 272000,
                'input_multiplier'  => 2.0,
                'output_multiplier' => 1.5,
            ],

            // キャッシュの書き込みが起きる、入力の最小のトークン数（これより短い入力はキャッシュされず、入力の料金になる）
            'cache_min_tokens' => 1024,

            // 料金表の照合に使う、OpenAIの公式のページ（毎日。AIを使わずに読むため、料金はかからない。D-31-03）
            'price_check' => [
                'model_url'   => env('BLOGOS_AI_PRICE_MODEL_URL', 'https://developers.openai.com/api/docs/models/{model}'),
                'pricing_url' => env('BLOGOS_AI_PRICE_PAGE_URL', 'https://developers.openai.com/api/docs/pricing'),
            ],

            // 1回あたりの出力トークン（推論のトークンを含む）の上限。超えた場合は途中で打ち切られ、失敗になる
            'max_output_tokens' => (int) env('BLOGOS_AI_MAX_OUTPUT_TOKENS', 48000),

            // 月の支出の上限（米ドル。日本時間の月初から数える。任意）。設定した場合、「今月の費用＋今回の最大の費用」がこれを超えるなら実行しない。
            // 空なら上限を設けない（OpenAI の残高の見込みでは判定する。下の credit。D-31-04）
            'monthly_budget_usd' => filled(env('BLOGOS_AI_MONTHLY_BUDGET_USD')) ? (float) env('BLOGOS_AI_MONTHLY_BUDGET_USD') : null,

            // 応答を待つ時間（秒）。推論が長いと数分かかる
            'timeout' => (int) env('BLOGOS_AI_TIMEOUT', 900),

            // Web検索（教材の調査・候補探し。D-30）。検索1回ごとに、トークンの料金とは別に料金がかかる（$10 / 1,000回。2026-09-28 に確認）。
            // 検索で読んだ内容は、入力のトークンとして別に課金される（応答のトークン数に含まれる）。
            // 1回の実行で検索する回数に上限を付ける（費用の上限の判定にも使う）
            'web_search' => [
                'tool'          => env('BLOGOS_AI_WEB_SEARCH_TOOL', 'web_search'),
                'cost_per_call' => (float) env('BLOGOS_AI_WEB_SEARCH_COST', 0.01),
                'max_calls'     => (int) env('BLOGOS_AI_WEB_SEARCH_MAX_CALLS', 8),
            ],
        ],

        // 画像（D-32）。画像そのものは画像モデル（gpt-image 系）が作る。図解の SVG・画像の指示文は、文章のモデル（ai.api.defaults.image_design）が作る
        'image' => [
            // 画像モデル。料金は1Mトークンあたりの米ドル（2026-09-28 に公式の料金ページで確認。料金表 ai_prices に入れて、毎日照合する）
            'model'   => env('BLOGOS_AI_IMAGE_MODEL', 'gpt-image-2.5-flare'),
            'quality' => env('BLOGOS_AI_IMAGE_QUALITY', 'medium'),
            'models'  => [
                'gpt-image-2.5-flare' => ['text_input' => 5.00, 'text_cached_input' => 1.25, 'image_input' => 8.00, 'image_cached_input' => 2.00, 'image_output' => 30.00],
            ],
            // 画面で選べる品質と、1枚の出力のトークン数の見積もり（実行前の費用の上限の判定に使う。多めに見積もる。実際の費用は応答のトークン数で計算する）
            'qualities' => [
                'low'    => 1500,
                'medium' => 4000,
                'high'   => 12000,
            ],
            // 種類ごとの画像の大きさ（アイキャッチは今の si-note に合わせて正方形）
            'sizes' => [
                'illustration' => '1536x1024',
                'eyecatch'     => '1024x1024',
            ],
            // アップロード・保存する画像の上限
            'max_upload_kb' => (int) env('BLOGOS_IMAGE_MAX_UPLOAD_KB', 10240),
            // ブラウザで縮める横幅の上限（ページの表示を重くしないため）と、図解の PNG の倍率（高解像度の画面でもぼやけないため）
            'max_width'     => 1600,
            'png_scale'     => 2,
        ],

        // OpenAI の残高（前払いのクレジット）の見込み（D-31-04）。人が OpenAI の画面で見た残高と課金した額を登録し、その後の費用の目安を引いて見込む
        'credit' => [
            // 残高の見込みがこの額以下になったら、画面で知らせる（OpenAI で課金して、BlogOS に登録する）
            'warning_usd' => (float) env('BLOGOS_AI_CREDIT_WARNING_USD', 3),
            // 「残高の見込み − 今回の最大の費用」がこの額を下回るなら、API実行をしない（見込みのずれに備えて残しておく額）
            'reserve_usd' => (float) env('BLOGOS_AI_CREDIT_RESERVE_USD', 0.5),
            // 最後に残高を登録してからこの日数が過ぎたら、実際の残高との照合を促す
            'reconcile_days' => (int) env('BLOGOS_AI_CREDIT_RECONCILE_DAYS', 30),
        ],

        // 条件による自動の再評価（品質診断だけ。D-25）。有効・無効と、使うモデル・推論の深さは、ブログごとに画面で設定する
        'auto_reevaluation' => [
            // 設定を作るときの初期値
            'model'  => env('BLOGOS_AI_AUTO_MODEL', 'gpt-6-luna'),
            'effort' => env('BLOGOS_AI_AUTO_EFFORT', 'medium'),

            // 1日に自動で再評価する記事の上限（ブログごと）。超えた分は翌日以降に回す
            // （最初の品質診断の記事の数。基準を満たすまでの改修と診断の繰り返しは数えない。D-65）
            'daily_limit' => (int) env('BLOGOS_AI_AUTO_DAILY_LIMIT', 30),

            // 診断の後の改修を、基準を満たすまで繰り返す回数（ブログごとに、記事の再評価のポップアップで 1〜max_revision_rounds から選ぶ。D-65）
            'default_revision_rounds' => 3,
            'max_revision_rounds'     => 5,

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

        // AIの出力を受け入れる目安の点数（人が判断する。D-07-03）。品質基準の合格（scoring.md：95点以上）に合わせる。
        // 記事の再評価で、基準を満たすまで改修を繰り返す目標の点数でもある（D-65。90 から 95 に上げた。D-70-07）
        'acceptance_score' => 95,

        // 1回の指示で実行する回数（D-07-03）
        'runs_per_instruction' => 1,
    ],

    /*
     * 収益用の教材（書籍・Udemy・スクール。D-30）
     */
    /*
    |--------------------------------------------------------------------------
    | 記事の HTML の仕上げ（D-34）
    |--------------------------------------------------------------------------
    |
    | AIの改修案・新規記事の本文に、BlogOS が入れるもの（広告のショートコード・広告を含むことの表示）の設定。
    | 品質基準のブログ別の定義（html-rules.md）ごとに持つ。定義のないブログには入れない。
    |
    */
    /*
    | タイトル・メタディスクリプションの確認（共通基準 writing.md 3-1・3-2。D-36）
    */
    'title_checks' => [
        // 検索結果で切れずに表示される先頭の文字数（この中にメインキーワードを入れる）と、タイトルの全体の目安
        'title_key_chars' => 28,
        'title_max_chars' => 40,
        // メタディスクリプションの文字数と、スマホで見える先頭の文字数
        'meta_min_chars'  => 80,
        'meta_max_chars'  => 120,
        'meta_key_chars'  => 50,
    ],

    'article_html' => [
        'si-note' => [
            // タイトルの書き出しの【…】は使わず、【〈技術〉入門】を最後に置く（article-types.md 1-2）
            'title_prefix_bracket' => false,
            // WP QUADS に登録した AdSense の広告ユニット（html-rules.md 4章）
            'ads' => [
                'top'     => 1, // rectangle-top：導入文の後
                'middle'  => 2, // rectangle-middle：本文の H2 の間
                'middle2' => 4, // rectangle-middle2：本文が長い記事だけ
                'bottom'  => 3, // rectangle-bottom：FAQ の後・まとめの前
            ],
            // id=4 を入れる「長い記事」（タグを除いた本文の文字数と、H2 の数の両方を満たす）
            'long_article' => ['chars' => 6000, 'h2' => 6],
            // 広告を含むことの表示（ステマ規制。html-rules.md 3-1）
            'pr_note' => '本記事にはプロモーション（アフィリエイト広告）を含みます。',
            // 1記事あたりの画像の依頼の上限（D-34）
            'image_limits' => ['diagram' => 3, 'illustration' => 1],
        ],
    ],

    'materials' => [
        // 記事ごとにAIへ渡す教材の候補の数（一段目の絞り込み。二段目でAIが選ぶ）
        'max_candidates' => (int) env('BLOGOS_MATERIAL_MAX_CANDIDATES', 8),

        // 定期チェック（AIの設定で有効にしたブログだけ。API実行）。前回の調査からこの月数が過ぎた教材を調べ直す
        'check' => [
            'interval_months' => (int) env('BLOGOS_MATERIAL_CHECK_MONTHS', 6),
            // 1日に調べる教材の上限（ブログごと）。超えた分は翌日以降に回す
            'daily_limit'     => (int) env('BLOGOS_MATERIAL_CHECK_DAILY_LIMIT', 5),
        ],
    ],

    /*
     * Googleのデータの取得（BLOGOS_DATABASE.md 12-2、D-21-07）
     */
    'google' => [
        // Googleの数値は数日のあいだ更新されるため、毎回この日数を取得し直す
        'refetch_days'   => 4,

        // 初めて取得するときに、何か月前から取得するか（Search Consoleは約16か月前までしか取得できない）
        'initial_months' => 16,

        // インデックスの登録状態の確認（Search Console の URL 検査 API。D-37）。1日の上限は、Search Console の上限（2,000件）より小さくする
        'index_inspection' => [
            'daily_limit'              => (int) env('BLOGOS_INDEX_INSPECTION_DAILY_LIMIT', 300),
            'recheck_days_indexed'     => 30,
            'recheck_days_not_indexed' => 7,
        ],
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

    /*
     * 音声の操作（ジャービス。D-58）
     *
     * 方式 c：録音 → 聞き取り（文字にする）→ 文章の AI が判断して BlogOS の道具を呼ぶ → 返事を声にする、を順番に行う。
     * 方式 d・e（リアルタイム会話）は段階3で加える。使う方式・声・話し方は、画面「AIの設定」の「音声」で選ぶ（system_settings）。
     */
    // XServer情報（D-71）。DB の容量の上限（MB）。MySQL からは読み取れないため、XServer のサーバーパネル（データベース → MySQL設定）の値を入れる。
    // 空にすると、使用率を出さない
    'server' => [
        'db_capacity_mb' => env('BLOGOS_DB_CAPACITY_MB', 5000),
        // XServer のサーバーパネル（ログインの画面）。XServer情報の画面にリンクを出す
        'panel_url' => env('BLOGOS_SERVER_PANEL_URL', 'https://secure.xserver.ne.jp/xapanel/login/xserver/server/'),
    ],

    'voice' => [
        // 聞き取り（音声 → 文字）・判断（文章の AI）・返事（文字 → 音声）のモデル
        'transcribe_model' => env('BLOGOS_VOICE_TRANSCRIBE_MODEL', 'gpt-transcribe'),
        'text_model'       => env('BLOGOS_VOICE_TEXT_MODEL', 'gpt-6-luna'),
        'text_effort'      => env('BLOGOS_VOICE_TEXT_EFFORT', 'none'),
        'tts_model'        => env('BLOGOS_VOICE_TTS_MODEL', 'gpt-4o-mini-tts'),

        // 選べる声（gpt-4o-mini-tts の13種類。2026-10-05 に公式のガイドで確認）と、初期値
        'voices'        => ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'],
        'default_voice' => 'cedar',

        // 話し方の指示の初期値
        'default_instructions' => '落ち着いた英国の執事のように、丁寧に、少し低めの声で、ゆっくり話してください。',

        // 1回の録音の上限（秒）と、1分あたりの回数の上限（使いすぎを防ぐ）
        'max_seconds'      => 30,
        'max_upload_kb'    => 4096,
        'turns_per_minute' => 10,

        // 判断で道具を呼ぶ回数の上限（1回の発言あたり）と、覚えておく直前のやり取りの数（「それを開いて」のような続きの発言のため）
        'max_tool_rounds' => 4,
        'history_turns'   => 4,

        // 料金（米ドル。2026-10-05 に公式の料金ページで確認）。費用の目安（AIの費用と残高）に使う。
        // 料金表（ai_prices）にあればその値を使い、毎日の公式のページとの照合で更新する（App\Services\Voice\VoicePrices。D-68-02）。
        // 聞き取りは1分あたり。返事の声は100万トークンあたり（文字の入力・音声の出力）で、1分あたりは音声の出力 × tts_audio_tokens_per_minute
        // （話す時間は文字数から見積もる）
        'prices' => [
            'transcribe_per_minute'       => ['gpt-transcribe' => 0.0045, 'gpt-4o-mini-transcribe' => 0.003, 'gpt-4o-transcribe' => 0.006],
            'tts'                         => ['gpt-4o-mini-tts' => ['text_input' => 0.60, 'audio_output' => 12.00]],
            'tts_audio_tokens_per_minute' => 1250,
        ],
        'chars_per_second' => 7,

        // リアルタイム会話（方式 d：mini、e：標準。段階3）。BlogOS が OpenAI からその場限りの鍵を受け取り、ブラウザが OpenAI と直接つながる（WebRTC）
        'realtime' => [
            'models' => [
                'd' => env('BLOGOS_VOICE_REALTIME_MODEL_D', 'gpt-realtime-2.1-mini'),
                'e' => env('BLOGOS_VOICE_REALTIME_MODEL_E', 'gpt-realtime-2.1'),
            ],
            // 会話の欄に出す、利用者の発言の文字起こし（会話とは別に、1分あたりの料金がかかる）
            'transcription_model' => env('BLOGOS_VOICE_REALTIME_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
            // 会話を開いておく上限（秒）と、話しかけがないと終える時間（秒）。費用の使いすぎを防ぐ
            'max_session_seconds' => 300,
            'idle_seconds'        => 60,
            // 料金（米ドル・100万トークンあたり。2026-10-05 に公式の料金ページで確認。料金表にあればその値。D-68-02）。音声のトークンは、聞く1分 約600・話す1分 約1,200
            'prices' => [
                'gpt-realtime-2.1-mini' => ['audio_input' => 10.00, 'audio_cached_input' => 0.30, 'audio_output' => 20.00, 'text_input' => 0.60, 'text_cached_input' => 0.06, 'text_output' => 2.40],
                'gpt-realtime-2.1'      => ['audio_input' => 32.00, 'audio_cached_input' => 0.40, 'audio_output' => 64.00, 'text_input' => 4.00, 'text_cached_input' => 0.40, 'text_output' => 24.00],
            ],
            'audio_tokens_per_second' => 10,
        ],
    ],

];
