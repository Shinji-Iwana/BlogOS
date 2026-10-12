<?php

namespace App\Support;

use App\Services\ServerStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WordPress の DB のテーブル（XServer情報から開く、WordPress の DB の一覧・詳細。D-81）。
 *
 * BlogOS の DB のユーザーで見える、ほかの DB（D-71-04）のテーブルを読むだけで、書き換えない（select だけ）。
 * WordPress の DB には外部キーの定義がないため、本体のテーブルのつながりは RELATIONS で決める。
 * 本体のテーブルは、接頭辞（wp_ など。options のテーブルから読む）を除いた名前（posts など）で扱う。
 */
class WordPressDatabaseTables
{
    /**
     * WordPress 本体と、プラグインのテーブルの日本語名（接頭辞を除いた名前 => 日本語名）
     */
    protected const LABELS = [
        'posts'              => '投稿・固定ページ・メディアなど',
        'postmeta'           => '投稿のメタ情報',
        'options'            => 'サイトの設定',
        'users'              => 'ユーザー',
        'usermeta'           => 'ユーザーのメタ情報',
        'terms'              => 'ターム（カテゴリ・タグなど）',
        'term_taxonomy'      => 'タームの分類',
        'term_relationships' => '投稿とタームのつながり',
        'termmeta'           => 'タームのメタ情報',
        'comments'           => 'コメント',
        'commentmeta'        => 'コメントのメタ情報',
        'links'              => 'リンク（旧機能）',

        // プラグインのテーブル（本番の si-note で使っているもの。利用者の要望で足す。D-81-06）
        'aioseo_posts'                       => 'AIOSEO：記事ごとのSEOの設定',
        'aioseo_notifications'               => 'AIOSEO：通知',
        'aioseo_cache'                       => 'AIOSEO：キャッシュ',
        'aioseo_ai_insights_keyword_reports' => 'AIOSEO：AIのキーワードのレポート',
        'aioseo_crawl_cleanup_blocked_args'  => 'AIOSEO：クロールの整理で止めたURLのパラメータ',
        'aioseo_crawl_cleanup_logs'          => 'AIOSEO：クロールの整理の記録',
        'aioseo_writing_assistant_keywords'  => 'AIOSEO：執筆アシスタントのキーワード',
        'aioseo_writing_assistant_posts'     => 'AIOSEO：執筆アシスタントの記事',
        'aioseo_seo_analyzer_results'        => 'AIOSEO：SEOの分析の結果',
        'actionscheduler_actions'            => 'Action Scheduler：予約した処理',
        'actionscheduler_logs'               => 'Action Scheduler：処理の記録',
        'actionscheduler_claims'             => 'Action Scheduler：実行する処理の確保',
        'actionscheduler_groups'             => 'Action Scheduler：処理のグループ',
        'ewwwio_images'                      => 'EWWW Image Optimizer：最適化した画像',
        'ewwwio_pages'                       => 'EWWW Image Optimizer：調べたページ',
        'ewwwio_queue'                       => 'EWWW Image Optimizer：最適化の待ち',
        'quads_clicks_desktop'               => 'WP QUADS：広告のクリック（パソコン）',
        'quads_clicks_mobile'                => 'WP QUADS：広告のクリック（スマートフォン）',
        'quads_impressions_desktop'          => 'WP QUADS：広告の表示（パソコン）',
        'quads_impressions_mobile'           => 'WP QUADS：広告の表示（スマートフォン）',
        'quads_logs'                         => 'WP QUADS：記録',
        'quads_adbuy_data'                   => 'WP QUADS：広告枠の販売のデータ',
        'quads_disabledad_data'              => 'WP QUADS：広告を消す機能のデータ',
    ];

    /**
     * 本体のテーブルのつながり（接頭辞を除いた名前 => [列 => つながる先のテーブル]）。値が 0 なら、つながる先はない
     */
    protected const RELATIONS = [
        'posts'              => ['post_author' => 'users', 'post_parent' => 'posts'],
        'postmeta'           => ['post_id' => 'posts'],
        'comments'           => ['comment_post_ID' => 'posts', 'comment_parent' => 'comments', 'user_id' => 'users'],
        'commentmeta'        => ['comment_id' => 'comments'],
        'usermeta'           => ['user_id' => 'users'],
        'term_taxonomy'      => ['term_id' => 'terms', 'parent' => 'terms'],
        'term_relationships' => ['object_id' => 'posts', 'term_taxonomy_id' => 'term_taxonomy'],
        'termmeta'           => ['term_id' => 'terms'],
        'links'              => ['link_owner' => 'users'],

        // プラグイン（D-81-06）
        'actionscheduler_actions'        => ['group_id' => 'actionscheduler_groups', 'claim_id' => 'actionscheduler_claims'],
        'actionscheduler_logs'           => ['action_id' => 'actionscheduler_actions'],
        'aioseo_posts'                   => ['post_id' => 'posts'],
        'aioseo_writing_assistant_posts' => ['post_id' => 'posts', 'keyword_id' => 'aioseo_writing_assistant_keywords'],
        'ewwwio_images'                  => ['attachment_id' => 'posts'],
        'ewwwio_queue'                   => ['attachment_id' => 'posts'],
    ];

    /**
     * 値を出さない列（接頭辞を除いた名前 => [列 => 説明]）
     */
    protected const SECRETS = [
        'users' => ['user_pass' => 'パスワードのハッシュを保存', 'user_activation_key' => 'パスワード再設定のための値を保存'],
        'posts' => ['post_password' => '記事を読むためのパスワードを保存'],
        // 広告枠の販売・広告を消す機能の支払いの応答（決済サービスの応答。支払った人の情報を含むことがある。D-81-06）
        'quads_adbuy_data'      => ['payment_response' => '決済サービスの応答を保存'],
        'quads_disabledad_data' => ['payment_response' => '決済サービスの応答を保存'],
    ];

    /**
     * 本体のテーブルの列の説明（接頭辞を除いた名前 => [列 => 説明]。詳細の列の名前の「?」に出す。D-81-05）
     */
    protected const COLUMNS = [
        'posts' => [
            'ID'                    => 'WordPress の中での、この投稿などの ID（管理画面や API で使う番号）。',
            'post_author'           => '作成者（ユーザーの ID）。',
            'post_date'             => '公開日時（サイトのタイムゾーンの時刻）。',
            'post_date_gmt'         => '公開日時（世界標準時。画面では日本時間に直して出す）。下書きなどで日時を決めていなければ 0000-00-00 00:00:00。',
            'post_content'          => '本文（ブロックの印を含む、入力したそのままの文字）。',
            'post_title'            => 'タイトル。',
            'post_excerpt'          => '抜粋。',
            'post_status'           => '状態（publish：公開、draft：下書き、pending：レビュー待ち、future：予約、private：非公開、trash：ゴミ箱、auto-draft：自動下書き、inherit：親に従う（リビジョン・メディア））。',
            'comment_status'        => 'コメントを受け付けるか（open：受け付ける、closed：受け付けない）。',
            'ping_status'           => 'ピンバック・トラックバックを受け付けるか（open／closed）。',
            'post_password'         => '記事を読むためのパスワード（パスワード保護のとき。空なら保護なし）。',
            'post_name'             => 'スラッグ（URL の一部になる名前。日本語は URL 用の文字（%E3…）で保存する）。',
            'to_ping'               => 'これから送るピンバックの URL（今はほとんど使わない）。',
            'pinged'                => '送ったピンバックの URL（今はほとんど使わない）。',
            'post_modified'         => '最終更新日時（サイトのタイムゾーンの時刻）。',
            'post_modified_gmt'     => '最終更新日時（世界標準時。画面では日本時間に直して出す）。',
            'post_content_filtered' => 'プラグインが使う、加工した本文（WordPress 本体は使わない。たいてい空）。',
            'post_parent'           => '親（投稿などの ID。固定ページの親、リビジョンの元の記事、メディアを添付した記事。0 は親なし）。',
            'guid'                  => '作ったときに付けた、重ならない印の URL（今の URL とは限らない。変えない）。',
            'menu_order'            => '並び順（固定ページの「順序」など。小さいほど前）。',
            'post_type'             => '種類（post：投稿、page：固定ページ、attachment：メディア、revision：リビジョン、nav_menu_item：メニューの項目、wp_template：テンプレート など。プラグインの独自の種類もある）。',
            'post_mime_type'        => 'ファイルの形式（メディアのとき。例：image/png）。',
            'comment_count'         => '承認済みのコメントの数。',
        ],
        'postmeta' => [
            'meta_id'    => 'この行の番号。',
            'post_id'    => 'どの投稿などのメタ情報か（投稿などの ID）。',
            'meta_key'   => 'メタ情報の名前（_ で始まるものは、管理画面に出さない内部の値。例：_thumbnail_id はアイキャッチ画像、_edit_lock は編集中の印、_blogos_draft_id は BlogOS の編集案の印）。',
            'meta_value' => 'メタ情報の値（配列などは PHP の形で保存する）。',
        ],
        'options' => [
            'option_id'    => 'この設定の番号。',
            'option_name'  => '設定の名前（例：siteurl・home・blogname・permalink_structure。プラグインの設定や、一時的な値（_transient_ で始まる）もある）。',
            'option_value' => '設定の値（配列などは PHP の形で保存する）。',
            'autoload'     => 'ページを開くたびに読み込むか（yes・on・auto-on：読み込む、no・off・auto-off：必要なときだけ読む。WordPress の版で値が違う）。',
        ],
        'users' => [
            'ID'                  => 'WordPress の中での、このユーザーの ID。',
            'user_login'          => 'ログインの名前（変えられない）。',
            'user_pass'           => 'パスワードのハッシュ（元のパスワードには戻せない形）。',
            'user_nicename'       => 'URL 用の名前（投稿者のページの URL に使う）。',
            'user_email'          => 'メールアドレス。',
            'user_url'            => 'サイトの URL（プロフィールで入力したもの）。',
            'user_registered'     => '登録した日時（世界標準時）。',
            'user_activation_key' => 'パスワードの再設定などで使う値（使っていなければ空）。',
            'user_status'         => '使っていない古い列（いつも 0）。',
            'display_name'        => 'サイトに出す名前（ブログ上の表示名）。',
        ],
        'usermeta' => [
            'umeta_id'   => 'この行の番号。',
            'user_id'    => 'どのユーザーのメタ情報か（ユーザーの ID）。',
            'meta_key'   => 'メタ情報の名前（例：nickname・description・wp_capabilities は権限グループ、session_tokens はログイン中のセッション）。',
            'meta_value' => 'メタ情報の値（配列などは PHP の形で保存する）。',
        ],
        'terms' => [
            'term_id'    => 'WordPress の中での、このタームの ID（カテゴリ・タグの ID）。',
            'name'       => '名前。',
            'slug'       => 'スラッグ（URL の一部になる名前）。',
            'term_group' => '別名のまとまり（今はほとんど使わない。いつも 0）。',
        ],
        'term_taxonomy' => [
            'term_taxonomy_id' => 'タームと分類の組み合わせの番号（投稿とのつながりは、この番号でつなぐ）。',
            'term_id'          => 'どのタームか（タームの ID）。',
            'taxonomy'         => '分類（category：カテゴリ、post_tag：タグ、nav_menu：メニュー、wp_theme：テーマ など。プラグインの独自の分類もある）。',
            'description'      => '説明（管理画面で入力した説明）。',
            'parent'           => '親（タームの ID。0 は親なし）。',
            'count'            => 'このタームが付いている、公開中の投稿などの数。',
        ],
        'term_relationships' => [
            'object_id'        => '投稿など（投稿などの ID。メニューの項目・リンクのこともある）。',
            'term_taxonomy_id' => '付いているタームの分類（term_taxonomy の番号）。',
            'term_order'       => '並び順（今はほとんど使わない）。',
        ],
        'termmeta' => [
            'meta_id'    => 'この行の番号。',
            'term_id'    => 'どのタームのメタ情報か（タームの ID）。',
            'meta_key'   => 'メタ情報の名前。',
            'meta_value' => 'メタ情報の値（配列などは PHP の形で保存する）。',
        ],
        'comments' => [
            'comment_ID'           => 'WordPress の中での、このコメントの ID。',
            'comment_post_ID'      => 'どの投稿などへのコメントか（投稿などの ID）。',
            'comment_author'       => '書いた人の名前。',
            'comment_author_email' => '書いた人のメールアドレス。',
            'comment_author_url'   => '書いた人のサイトの URL。',
            'comment_author_IP'    => '書いた人の IP アドレス。',
            'comment_date'         => '書いた日時（サイトのタイムゾーンの時刻）。',
            'comment_date_gmt'     => '書いた日時（世界標準時。画面では日本時間に直して出す）。',
            'comment_content'      => 'コメントの本文。',
            'comment_karma'        => '使っていない古い列（いつも 0）。',
            'comment_approved'     => '承認の状態（1：承認済み、0：承認待ち、spam：スパム、trash：ゴミ箱）。',
            'comment_agent'        => '書いた人のブラウザの情報。',
            'comment_type'         => '種類（comment：コメント、pingback・trackback など）。',
            'comment_parent'       => '返信のときの、元のコメント（コメントの ID。0 は返信でない）。',
            'user_id'              => 'ログインしたユーザーが書いたなら、そのユーザー（ユーザーの ID。0 はログインしていない人）。',
        ],
        'commentmeta' => [
            'meta_id'    => 'この行の番号。',
            'comment_id' => 'どのコメントのメタ情報か（コメントの ID）。',
            'meta_key'   => 'メタ情報の名前。',
            'meta_value' => 'メタ情報の値（配列などは PHP の形で保存する）。',
        ],
        'links' => [
            'link_id'          => 'このリンクの番号（リンクマネージャー。今の WordPress では、プラグインを入れなければ使わない）。',
            'link_url'         => 'リンク先の URL。',
            'link_name'        => 'リンクの名前。',
            'link_image'       => 'リンクの画像の URL。',
            'link_target'      => '開き方（_blank：別のタブ など）。',
            'link_description' => '説明。',
            'link_visible'     => '公開するか（Y：公開、N：非公開）。',
            'link_owner'       => '登録したユーザー（ユーザーの ID）。',
            'link_rating'      => '評価（0〜10）。',
            'link_updated'     => '更新日時。',
            'link_rel'         => 'リンクの関係（XFN）。',
            'link_notes'       => 'メモ。',
            'link_rss'         => 'RSS の URL。',
        ],

        // プラグインのテーブル（列は本番の si-note のとおり。プラグインの版で変わることがある。D-81-06）
        'actionscheduler_actions' => [
            'action_id'            => 'この予約した処理の番号。',
            'hook'                 => '実行する処理の名前（WordPress のフック。どのプラグインの処理かが分かる。例：aioseo_ で始まるものは AIOSEO）。',
            'status'               => '状態（pending：待ち、in-progress：実行中、complete：完了、failed：失敗、canceled：取り消し）。',
            'scheduled_date_gmt'   => '実行する予定の日時（世界標準時。画面では日本時間に直して出す）。',
            'scheduled_date_local' => '実行する予定の日時（サイトのタイムゾーンの時刻）。',
            'priority'             => '優先度（小さいほど先に実行する。標準は 10）。',
            'args'                 => '処理に渡す値（JSON。長いときは extended_args に入れ、ここには要約を入れる）。',
            'schedule'             => '繰り返しの設定（1回だけ・一定の間隔・cron の形など。PHP の形で保存する）。',
            'group_id'             => '処理のグループ（Action Scheduler の処理のグループの番号。0 はグループなし）。',
            'attempts'             => '実行を試した回数。',
            'last_attempt_gmt'     => '最後に実行を試した日時（世界標準時）。',
            'last_attempt_local'   => '最後に実行を試した日時（サイトのタイムゾーンの時刻）。',
            'claim_id'             => '実行するために確保した印（Action Scheduler の実行する処理の確保の番号。0 は確保していない）。',
            'extended_args'        => '処理に渡す値（args に入らない長いときの全文。JSON）。',
        ],
        'actionscheduler_claims' => [
            'claim_id'         => 'この確保の番号（処理を実行するときに、同じ処理が二重に動かないよう、実行する処理をまとめて確保する）。',
            'date_created_gmt' => '確保した日時（世界標準時）。',
        ],
        'actionscheduler_groups' => [
            'group_id' => 'このグループの番号。',
            'slug'     => 'グループの名前（どのプラグインの処理か。例：aioseo）。',
        ],
        'actionscheduler_logs' => [
            'log_id'         => 'この記録の番号。',
            'action_id'      => 'どの処理の記録か（Action Scheduler の予約した処理の番号）。',
            'message'        => '記録の内容（作った・始めた・完了した・失敗した理由など）。',
            'log_date_gmt'   => '記録した日時（世界標準時。画面では日本時間に直して出す）。',
            'log_date_local' => '記録した日時（サイトのタイムゾーンの時刻）。',
        ],
        'aioseo_ai_insights_keyword_reports' => [
            'id'               => 'このレポートの番号。',
            'uuid'             => 'レポートの印（AIOSEO のサービスで使う、重ならない文字）。',
            'keyword'          => '調べたキーワード。',
            'status'           => 'レポートの状態（作成中・完了など）。',
            'brands'           => 'AI の回答に出たブランドの一覧（JSON）。',
            'brands_mentioned' => 'AI の回答に出たブランドの数。',
            'results'          => 'AI の回答の結果（JSON）。',
            'created'          => '作った日時。',
            'updated'          => '最後に変えた日時。',
        ],
        'aioseo_cache' => [
            'id'         => 'このキャッシュの番号。',
            'key'        => 'キャッシュの名前。',
            'value'      => 'キャッシュの値（AIOSEO のサービスからの応答など。PHP の形で保存する）。名前に key・license などを含むものは出さない。',
            'is_object'  => '値がオブジェクトか（1：オブジェクト）。',
            'expiration' => 'キャッシュが切れる日時。',
            'created'    => '作った日時。',
            'updated'    => '最後に変えた日時。',
            'name'       => 'キャッシュの名前（key と同じものが入ることが多い）。',
        ],
        'aioseo_crawl_cleanup_blocked_args' => [
            'id'               => 'この行の番号。',
            'param'            => '止める URL のパラメータの名前と値（例：utm_source=xxx）。',
            'key'              => 'パラメータの名前。',
            'value'            => 'パラメータの値（空なら、値によらず止める）。',
            'key_value_hash'   => 'key と value の組み合わせのハッシュ（同じものを見分けるのに使う）。',
            'regex'            => '止める URL のパラメータの正規表現（正規表現で決めたとき）。',
            'hits'             => 'このパラメータの付いた URL に、クロールが来た回数。',
            'created'          => '作った日時。',
            'updated'          => '最後に変えた日時。',
            'param_value_hash' => 'param のハッシュ（同じものを見分けるのに使う）。',
        ],
        'aioseo_crawl_cleanup_logs' => [
            'id'      => 'この記録の番号。',
            'slug'    => 'クロールが来た URL のパス。',
            'param'   => '付いていた URL のパラメータ（名前と値）。',
            'key'     => 'パラメータの名前。',
            'value'   => 'パラメータの値。',
            'hash'    => 'URL とパラメータのハッシュ（同じものを見分けるのに使う）。',
            'hits'    => 'クロールが来た回数。',
            'created' => '最初に記録した日時。',
            'updated' => '最後に記録した日時。',
        ],
        'aioseo_notifications' => [
            'id'                => 'この通知の番号。',
            'slug'              => '通知の印（重ならない短い文字）。',
            'addon'             => '通知を出したアドオン（本体の通知なら空）。',
            'title'             => '通知の題名。',
            'content'           => '通知の本文（HTML）。',
            'type'              => '通知の種類。',
            'level'             => '通知を出す相手（AIOSEO の無料版・有料版のどれに出すかなど。JSON）。',
            'notification_id'   => 'AIOSEO のサービスの通知の ID（サービスから届いた通知のとき）。',
            'notification_name' => '通知の名前（AIOSEO の中で決めた名前。サービスから届いた通知なら空）。',
            'start'             => '通知を出し始める日時。',
            'end'               => '通知を出し終える日時。',
            'button1_label'     => '1つ目のボタンの文字。',
            'button1_action'    => '1つ目のボタンを押したときの動き（URL など）。',
            'button2_label'     => '2つ目のボタンの文字。',
            'button2_action'    => '2つ目のボタンを押したときの動き（URL など）。',
            'dismissed'         => '閉じたか（1：閉じた）。',
            'new'               => '新しい通知か（1：まだ見ていない）。',
            'created'           => '作った日時。',
            'updated'           => '最後に変えた日時。',
        ],
        'aioseo_posts' => [
            'id'                          => 'この行の番号（記事1件につき1行）。',
            'post_id'                     => 'どの記事の設定か（投稿などの ID）。',
            'title'                       => 'SEO のタイトル（検索結果に出るタイトル。#post_title などの置き換えの印を含む）。空なら、AIOSEO の標準の形。',
            'description'                 => 'メタディスクリプション（検索結果に出る説明文。置き換えの印を含む）。空なら、AIOSEO の標準の形。',
            'keywords'                    => 'メタキーワード（JSON。今の検索エンジンはほとんど使わない）。',
            'keyphrases'                  => 'フォーカスキーフレーズと追加のキーフレーズ、その分析の結果（JSON）。',
            'page_analysis'               => 'TruSEO のページの分析の結果（JSON）。',
            'primary_term'                => '主のカテゴリ（パンくずリストや URL に使う。JSON）。',
            'canonical_url'               => '正規の URL（空なら、記事の URL）。',
            'og_title'                    => 'SNS（Facebook など）で共有したときのタイトル（Open Graph）。',
            'og_description'              => 'SNS で共有したときの説明（Open Graph）。',
            'og_object_type'              => 'Open Graph の種類（article など。default なら標準の設定）。',
            'og_image_type'               => 'SNS で共有したときの画像の選び方（default：標準、featured：アイキャッチ、content：本文の最初の画像、custom_image：自分で決めた画像 など）。',
            'og_image_url'                => 'SNS で共有したときの画像の URL（選び方から決まった画像）。',
            'og_image_width'              => 'その画像の幅（ピクセル）。',
            'og_image_height'             => 'その画像の高さ（ピクセル）。',
            'og_image_custom_url'         => '自分で決めた画像の URL（og_image_type が custom_image のとき）。',
            'og_image_custom_fields'      => '画像の URL を取るカスタムフィールドの名前（og_image_type が custom のとき）。',
            'og_video'                    => 'SNS で共有したときの動画の URL。',
            'og_custom_url'               => 'SNS で共有したときの URL（空なら、記事の URL）。',
            'og_article_section'          => 'Open Graph の記事の分野。',
            'og_article_tags'             => 'Open Graph の記事のタグ（JSON）。',
            'twitter_use_og'              => 'X（Twitter）で、Open Graph と同じ内容を使うか（1：使う）。',
            'twitter_card'                => 'X（Twitter）のカードの形（default・summary・summary_large_image など）。',
            'twitter_image_type'          => 'X（Twitter）の画像の選び方（og_image_type と同じ値）。',
            'twitter_image_url'           => 'X（Twitter）の画像の URL。',
            'twitter_image_custom_url'    => 'X（Twitter）に自分で決めた画像の URL。',
            'twitter_image_custom_fields' => 'X（Twitter）の画像の URL を取るカスタムフィールドの名前。',
            'twitter_title'               => 'X（Twitter）で共有したときのタイトル。',
            'twitter_description'         => 'X（Twitter）で共有したときの説明。',
            'seo_score'                   => 'TruSEO の点数（0〜100）。',
            'schema'                      => '構造化データ（スキーマ）の設定（JSON）。',
            'schema_type'                 => '構造化データの種類（古い版の設定。今は schema に入る）。',
            'schema_type_options'         => '構造化データの種類ごとの設定（古い版の設定。JSON）。',
            'pillar_content'              => 'コーナーストーン（柱の記事）か（1：そう）。',
            'robots_default'              => '検索エンジンへの指示（robots）を、標準の設定にするか（1：標準。0 なら、下の robots_ の値を使う）。',
            'robots_noindex'              => '検索結果に出さない（noindex）か（1：出さない）。',
            'robots_noarchive'            => '検索エンジンのキャッシュを残させない（noarchive）か。',
            'robots_nosnippet'            => '検索結果に説明を出させない（nosnippet）か。',
            'robots_nofollow'             => 'ページの中のリンクをたどらせない（nofollow）か。',
            'robots_noimageindex'         => '画像を検索結果に出させない（noimageindex）か。',
            'robots_noodp'                => '古い指示（noodp。今の検索エンジンは使わない）。',
            'robots_notranslate'          => '検索結果で翻訳させない（notranslate）か。',
            'robots_max_snippet'          => '検索結果の説明の最大の文字数（-1 は制限なし）。',
            'robots_max_videopreview'     => '検索結果の動画のプレビューの最大の秒数（-1 は制限なし）。',
            'robots_max_imagepreview'     => '検索結果の画像のプレビューの大きさ（none・standard・large）。',
            'images'                      => 'サイトマップに載せる、記事の中の画像の一覧（JSON）。',
            'image_scan_date'             => '記事の中の画像を最後に調べた日時。',
            'priority'                    => 'サイトマップの優先度（0〜1。空なら標準）。',
            'frequency'                   => 'サイトマップの更新の頻度（default・daily・weekly など）。',
            'videos'                      => 'サイトマップに載せる、記事の中の動画の一覧（JSON）。',
            'video_thumbnail'             => '動画のサムネイルの URL。',
            'video_scan_date'             => '記事の中の動画を最後に調べた日時。',
            'local_seo'                   => 'ローカル SEO（お店の情報など）の設定（JSON）。',
            'breadcrumb_settings'         => 'パンくずリストの設定（JSON）。',
            'limit_modified_date'         => '小さな直しで、最終更新日時を変えないようにしたか（1：変えない）。',
            'options'                     => 'そのほかの設定（JSON）。',
            'ai'                          => 'AI の機能で作った値（タイトル・説明の案など。JSON）。',
            'created'                     => '作った日時。',
            'updated'                     => '最後に変えた日時。',
            'seo_analyzer_scan_date'      => 'SEO の分析で最後に調べた日時。',
            'focus_keyword'               => 'フォーカスキーワード（古い版の設定。今は keyphrases に入る）。',
            'truseo'                      => 'TruSEO の分析の結果（JSON）。',
            'additional_keywords'         => '追加のキーワード（古い版の設定）。',
            'truseo_locale'               => 'TruSEO の分析に使った言語（例：ja）。',
        ],
        'aioseo_seo_analyzer_results' => [
            'id'             => 'この結果の番号。',
            'data'           => '分析の結果（JSON）。',
            'score'          => 'SEO の分析の点数。',
            'competitor_url' => '比べた競合のサイトの URL（自分のサイトの分析なら空）。',
            'created'        => '作った日時。',
            'updated'        => '最後に変えた日時。',
        ],
        'aioseo_writing_assistant_keywords' => [
            'id'          => 'このキーワードの番号。',
            'uuid'        => 'キーワードの印（AIOSEO のサービスで使う、重ならない文字）。',
            'keyword'     => '調べたキーワード。',
            'country'     => '調べた国（例：JP）。',
            'language'    => '調べた言語（例：ja）。',
            'progress'    => '調べた進み具合（0〜100）。',
            'keywords'    => '関係するキーワードの一覧（JSON）。',
            'competitors' => '上位の競合のページの情報（JSON）。',
            'created'     => '作った日時。',
            'updated'     => '最後に変えた日時。',
        ],
        'aioseo_writing_assistant_posts' => [
            'id'                    => 'この行の番号。',
            'post_id'               => 'どの記事か（投稿などの ID）。',
            'keyword_id'            => '使ったキーワード（AIOSEO の執筆アシスタントのキーワードの番号）。',
            'content_analysis_hash' => '分析したときの本文のハッシュ（本文が変わったかを見分けるのに使う）。',
            'content_analysis'      => '本文の分析の結果（JSON）。',
            'created'               => '作った日時。',
            'updated'               => '最後に変えた日時。',
        ],
        'ewwwio_images' => [
            'id'             => 'この画像の番号。',
            'attachment_id'  => 'どのメディアの画像か（投稿などの ID。メディアでない画像なら空）。',
            'gallery'        => '画像の置き場所の種類（media：メディアライブラリ など。空ならメディアライブラリの外）。',
            'resize'         => '画像の大きさの種類（full：元の大きさ、thumbnail・medium・large など）。',
            'path'           => '画像のファイルの場所（サーバーの中のパス。ABSPATH などの印で短くしている）。',
            'converted'      => '別の形式に変えたときの、元のファイルの場所（例：PNG から JPG）。',
            'image_size'     => '最適化した後の大きさ（バイト）。',
            'orig_size'      => '最適化する前の大きさ（バイト）。',
            'backup'         => 'バックアップの印（クラウドに元の画像を残したとき）。',
            'level'          => '最適化の強さ（圧縮の段階）。',
            'pending'        => '最適化の待ちか（1：待ち）。',
            'updates'        => '最適化し直した回数。',
            'updated'        => '最後に最適化した日時。',
            'retrieve'       => 'クラウドで最適化した画像を受け取る印（受け取ったら空）。',
            'resized_width'  => '縮めた後の幅（ピクセル。縮めていなければ 0）。',
            'resized_height' => '縮めた後の高さ（ピクセル。縮めていなければ 0）。',
            'resize_error'   => '縮めるときのエラーの印（0：なし）。',
            'webp_size'      => 'WebP にした画像の大きさ（バイト。作っていなければ 0）。',
            'webp_error'     => 'WebP にするときのエラーの印（0：なし）。',
        ],
        'ewwwio_pages' => [
            'id'   => 'この行の番号。',
            'page' => '調べたページ（URL など）。',
            'data' => '調べた結果。',
        ],
        'ewwwio_queue' => [
            'id'            => 'この行の番号。',
            'attachment_id' => '最適化を待っているメディア（投稿などの ID）。',
            'gallery'       => '画像の置き場所の種類（media：メディアライブラリ など）。',
            'scanned'       => '最適化が要るかを調べ終えたか（1：調べた）。',
            'new'           => '新しくアップロードした画像か（1：新しい）。',
            'convert_once'  => '1回だけ別の形式に変えるか（1：変える）。',
            'force_reopt'   => '最適化済みでも、もう一度最適化するか（1：する）。',
            'force_smart'   => '圧縮の強さを自動で決め直すか（1：決め直す）。',
            'webp_only'     => 'WebP だけを作るか（1：WebP だけ）。',
        ],
        'quads_adbuy_data' => [
            'id'               => 'この行の番号。',
            'user_id'          => '広告枠を買った人（ユーザーの ID）。',
            'ad_id'            => 'どの広告枠か（WP QUADS の広告の ID）。',
            'ad_content'       => '広告の文。',
            'ad_link'          => '広告のリンク先の URL。',
            'ad_image'         => '広告の画像の URL。',
            'start_date'       => '広告を出し始める日時。',
            'end_date'         => '広告を出し終える日時。',
            'payment_response' => '決済サービスの応答（支払った人の情報を含むことがあるため、値は出さない）。',
            'payment_status'   => '支払いの状態。',
            'ad_status'        => '広告の状態。',
        ],
        'quads_clicks_desktop' => [
            'id'           => 'この行の番号。',
            'ad_id'        => 'どの広告か（WP QUADS の広告の ID）。',
            'stats_date'   => 'どの日の数か（UNIX 時間。秒）。',
            'stats_clicks' => 'その日の、パソコンでのクリック数。',
            'stats_year'   => 'どの年の数か。',
        ],
        'quads_clicks_mobile' => [
            'id'           => 'この行の番号。',
            'ad_id'        => 'どの広告か（WP QUADS の広告の ID）。',
            'stats_date'   => 'どの日の数か（UNIX 時間。秒）。',
            'stats_clicks' => 'その日の、スマートフォンでのクリック数。',
            'stats_year'   => 'どの年の数か。',
        ],
        'quads_disabledad_data' => [
            'disable_ad_id'    => 'この行の番号。',
            'user_id'          => '広告を消す機能を買った人（ユーザーの ID）。',
            'payment_response' => '決済サービスの応答（支払った人の情報を含むことがあるため、値は出さない）。',
            'disable_cost'     => '支払った額。',
            'payment_status'   => '支払いの状態。',
            'disable_status'   => '広告を消す機能の状態。',
            'disable_duration' => '広告を消す期間。',
            'username'         => '買った人の名前。',
            'user_email'       => '買った人のメールアドレス。',
            'disable_date'     => '広告を消し始めた日。',
        ],
        'quads_impressions_desktop' => [
            'id'                => 'この行の番号。',
            'ad_id'             => 'どの広告か（WP QUADS の広告の ID）。',
            'stats_date'        => 'どの日の数か（UNIX 時間。秒）。',
            'stats_impressions' => 'その日の、パソコンでの表示回数。',
            'stats_year'        => 'どの年の数か。',
        ],
        'quads_impressions_mobile' => [
            'id'                => 'この行の番号。',
            'ad_id'             => 'どの広告か（WP QUADS の広告の ID）。',
            'stats_date'        => 'どの日の数か（UNIX 時間。秒）。',
            'stats_impressions' => 'その日の、スマートフォンでの表示回数。',
            'stats_year'        => 'どの年の数か。',
        ],
        'quads_logs' => [
            'id'         => 'この記録の番号。',
            'ad_id'      => 'どの広告か（WP QUADS の広告の ID）。',
            'log_date'   => '記録した時刻（UNIX 時間。秒）。',
            'log_clicks' => 'クリック数。',
            'ip_address' => '見た人の IP アドレス。',
            'log_url'    => '広告が出たページの URL。',
            'browser'    => '見た人のブラウザ。',
            'referrer'   => '見た人がどこから来たか（リファラー）。',
        ],
    ];

    /**
     * 値を出さないメタ情報（usermeta の meta_key => 説明）
     */
    protected const SECRET_USER_META = [
        'session_tokens'          => 'ログイン中のセッションを保存',
        '_application_passwords'  => 'Application Password のハッシュを保存',
    ];

    /**
     * 値を出さないサイトの設定（options の option_name にこの文字を含むもの。プラグインの API キーなど）
     */
    protected const SECRET_OPTION_WORDS = ['key', 'secret', 'token', 'password'];

    /**
     * リンクの文字に使う列（先にあるものを使う）
     */
    protected const TEXT_COLUMNS = ['post_title', 'display_name', 'user_login', 'name', 'option_name', 'meta_key', 'taxonomy', 'comment_author', 'link_name', 'hook', 'keyword', 'slug', 'key'];

    /** @var array<string, mixed> 1回の画面の表示の中で、DB から読んだ構造を使い回す */
    protected array $memo = [];

    /**
     * 見える WordPress などの DB の名前（XServer情報に出すもの）
     *
     * @return list<string>
     */
    public function schemas(): array
    {
        return $this->memo['schemas'] ??= app(ServerStatusService::class)->otherSchemas();
    }

    /**
     * DB のテーブル名の一覧
     *
     * @return list<string>
     */
    public function tables(string $schema): array
    {
        return $this->memo['tables'][$schema] ??= collect(DB::select(
            'select TABLE_NAME as name from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_TYPE = ? order by TABLE_NAME',
            [$schema, 'BASE TABLE'],
        ))->pluck('name')->all();
    }

    public function exists(string $schema, string $table): bool
    {
        return in_array($schema, $this->schemas(), true) && in_array($table, $this->tables($schema), true);
    }

    /**
     * WordPress のテーブルの接頭辞（options のテーブルから読む。WordPress の DB でなければ null）
     */
    public function prefix(string $schema): ?string
    {
        if (! array_key_exists($schema, $this->memo['prefix'] ?? [])) {
            $table = DB::selectOne(
                'select TABLE_NAME as name from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME like ? and COLUMN_NAME = ? order by length(TABLE_NAME) limit 1',
                [$schema, '%options', 'option_name'],
            );
            $this->memo['prefix'][$schema] = $table ? substr($table->name, 0, -strlen('options')) : null;
        }

        return $this->memo['prefix'][$schema];
    }

    /**
     * 本体のテーブルなら、接頭辞を除いた名前（posts など。本体のテーブルでなければ null）
     */
    public function coreName(string $schema, string $table): ?string
    {
        $prefix = $this->prefix($schema);
        if ($prefix === null || ! str_starts_with($table, $prefix)) {
            return null;
        }
        $name = substr($table, strlen($prefix));

        return isset(self::LABELS[$name]) ? $name : null;
    }

    /**
     * 本体のテーブルの名前から、この DB のテーブル名（wp_posts など）
     */
    public function tableName(string $schema, string $coreName): string
    {
        return $this->prefix($schema) . $coreName;
    }

    /**
     * 日本語名（本体のテーブルだけ。なければ null）
     */
    public function label(string $schema, string $table): ?string
    {
        return ($name = $this->coreName($schema, $table)) !== null ? self::LABELS[$name] : null;
    }

    /**
     * 列の説明（本体のテーブルだけ。なければ null。D-81-05）
     */
    public function describe(string $schema, string $table, string $column): ?string
    {
        return self::COLUMNS[$this->coreName($schema, $table)][$column] ?? null;
    }

    /**
     * 本体のテーブルの、説明を書いた列（テスト用。接頭辞を除いた名前 => 列の一覧）
     *
     * @return array<string, list<string>>
     */
    public static function describedColumns(): array
    {
        return array_map('array_keys', self::COLUMNS);
    }

    /**
     * 列の一覧（name・type。並びは表の列の順）
     *
     * @return list<array{name: string, type: string}>
     */
    public function columns(string $schema, string $table): array
    {
        return $this->memo['columns']["{$schema}.{$table}"] ??= collect(DB::select(
            'select COLUMN_NAME as name, DATA_TYPE as type from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? order by ORDINAL_POSITION',
            [$schema, $table],
        ))->map(fn ($column) => ['name' => $column->name, 'type' => strtolower($column->type)])->all();
    }

    /**
     * 主キーの列（1列でなければ null。詳細へのリンクを出さない）
     */
    public function primaryKey(string $schema, string $table): ?string
    {
        if (! array_key_exists("{$schema}.{$table}", $this->memo['primary'] ?? [])) {
            $columns = collect(DB::select(
                'select COLUMN_NAME as name from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = ? and TABLE_NAME = ? and CONSTRAINT_NAME = ?',
                [$schema, $table, 'PRIMARY'],
            ))->pluck('name')->all();
            $this->memo['primary']["{$schema}.{$table}"] = count($columns) === 1 ? $columns[0] : null;
        }

        return $this->memo['primary']["{$schema}.{$table}"];
    }

    /**
     * 一覧に出す列（とても長い文章・バイナリの列は、詳細だけで出す。WordPress はタイトルも text のため、text は出す）
     *
     * @return list<string>
     */
    public function listColumns(string $schema, string $table): array
    {
        return collect($this->columns($schema, $table))
            ->reject(fn ($column) => in_array($column['type'], ['mediumtext', 'longtext', 'json'], true) || str_contains($column['type'], 'blob'))
            ->pluck('name')->values()->all();
    }

    /**
     * 絞り込みの対象の列（文字の列。とても長い文章と、秘密の値の列は除く）
     *
     * @return list<string>
     */
    public function searchColumns(string $schema, string $table): array
    {
        $core = $this->coreName($schema, $table);

        return collect($this->columns($schema, $table))
            ->filter(fn ($column) => in_array($column['type'], ['varchar', 'char', 'tinytext', 'text'], true))
            ->pluck('name')
            ->reject(fn ($column) => isset(self::SECRETS[$core][$column]))
            ->values()->all();
    }

    /**
     * つながる先のある列（列 => つながる先のテーブル名）
     *
     * @return array<string, string>
     */
    public function foreignKeys(string $schema, string $table): array
    {
        $core = $this->coreName($schema, $table);

        return collect(self::RELATIONS[$core] ?? [])
            ->map(fn (string $target) => $this->tableName($schema, $target))
            ->filter(fn (string $target) => in_array($target, $this->tables($schema), true))
            ->all();
    }

    /**
     * このテーブルを指しているテーブルと列（関連のパネル）
     *
     * @return list<array{table: string, column: string}>
     */
    public function referencedBy(string $schema, string $table): array
    {
        $core = $this->coreName($schema, $table);
        $references = [];
        foreach (self::RELATIONS as $source => $columns) {
            foreach ($columns as $column => $target) {
                $sourceTable = $this->tableName($schema, $source);
                if ($target === $core && in_array($sourceTable, $this->tables($schema), true)) {
                    $references[] = ['table' => $sourceTable, 'column' => $column];
                }
            }
        }

        return $references;
    }

    /**
     * 画面に出す値（秘密の値は「****（説明）」。行によって隠すもの（ユーザーのメタ情報・サイトの設定）は、行の値で決める）
     */
    public function display(string $schema, string $table, string $column, mixed $value, array $row = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (($secret = $this->secret($schema, $table, $column, $row)) !== null) {
            return "****（{$secret}）";
        }
        // _gmt の日時は日本時間にする（D-72-04。WordPress が「日時なし」で入れる 0000-00-00 00:00:00 は、そのまま出す）
        if (is_string($value) && str_starts_with($value, '0000-00-00')) {
            return $value;
        }

        return DisplayTime::value($column, $value);
    }

    /**
     * 値を出さない列なら、その説明（なければ null）
     */
    public function secret(string $schema, string $table, string $column, array $row = []): ?string
    {
        $core = $this->coreName($schema, $table);
        if (isset(self::SECRETS[$core][$column])) {
            return self::SECRETS[$core][$column];
        }
        if ($core === 'usermeta' && $column === 'meta_value') {
            return self::SECRET_USER_META[$row['meta_key'] ?? ''] ?? null;
        }
        if ($core === 'options' && $column === 'option_value' && Str::contains(strtolower((string) ($row['option_name'] ?? '')), self::SECRET_OPTION_WORDS)) {
            return 'API キーなどの秘密の値かもしれないため、表示しません';
        }
        // AIOSEO のキャッシュも、名前に key などを含むもの（ライセンスの情報など）は出さない（D-81-06）
        if ($core === 'aioseo_cache' && $column === 'value' && Str::contains(strtolower((string) ($row['key'] ?? '')), [...self::SECRET_OPTION_WORDS, 'license'])) {
            return 'ライセンスなどの秘密の値かもしれないため、表示しません';
        }

        return null;
    }

    /**
     * つながる先のレコードのリンク（{text, url}。値が空・0 なら null。レコードがなければ、リンクにせず「（記録にない）」と出す）
     *
     * @return array{text: string, url: string|null}|null
     */
    public function link(string $schema, string $table, mixed $id): ?array
    {
        if ($id === null || $id === '' || (string) $id === '0' || ($key = $this->primaryKey($schema, $table)) === null) {
            return null;
        }

        $row = DB::table("{$schema}.{$table}")->where($key, $id)->first();
        if ($row === null) {
            return ['text' => "{$id}（記録にない）", 'url' => null];
        }

        $text = collect(self::TEXT_COLUMNS)->map(fn ($column) => $row->{$column} ?? null)->first(fn ($value) => $value !== null && $value !== '');
        $text = $text !== null ? Str::limit((string) $text, 60) : ($this->label($schema, $table) ?? $table);

        return ['text' => "{$text} #{$id}", 'url' => route('database.wordpress-db.show', ['schema' => $schema, 'table' => $table, 'id' => $id])];
    }

    /**
     * BlogOS に取り込んだ同じデータ（投稿・固定ページ・メディア・カテゴリ・タグ・投稿者）へのリンク（なければ null）
     *
     * @return array{text: string, url: string}|null
     */
    public function blogosLink(string $schema, string $table, array $row): ?array
    {
        $blog = app(ServerStatusService::class)->blogForSchema($schema);
        $core = $this->coreName($schema, $table);
        [$blogosTable, $wordpressId] = match ($core) {
            'posts'         => [['post' => 'posts', 'page' => 'pages', 'attachment' => 'media'][$row['post_type'] ?? ''] ?? null, $row['ID'] ?? null],
            'term_taxonomy' => [['category' => 'categories', 'post_tag' => 'tags'][$row['taxonomy'] ?? ''] ?? null, $row['term_id'] ?? null],
            'users'         => ['authors', $row['ID'] ?? null],
            default         => [null, null],
        };
        if ($blog === null || $blogosTable === null || $wordpressId === null) {
            return null;
        }

        $id = DB::table($blogosTable)->where('blog_id', $blog->id)->where('wordpress_id', $wordpressId)->value('id');

        return $id === null ? null : [
            'text' => DatabaseTables::label($blogosTable) . " #{$id}",
            'url'  => route('database.tables.show', ['table' => $blogosTable, 'id' => $id]),
        ];
    }
}
