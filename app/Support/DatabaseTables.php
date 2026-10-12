<?php

namespace App\Support;

use App\Http\Controllers\Database\WordPressRecordController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BlogOS の DB のテーブル（DB確認画面の全テーブルの一覧・詳細。D-80）。
 *
 * テーブルの日本語名・秘密の値の列・変更履歴のテーブルを持ち、列・主キー・外部キーは DB から読む。
 * 取り込んだ WordPress のデータの11テーブルは、その画面（WordPressRecordController）の名前と画面を使う。
 * テーブルを足したときは、LABELS に日本語名を足す（ない名前はテーブル名だけで出す）。
 */
class DatabaseTables
{
    /**
     * テーブルの日本語名（取り込んだ WordPress のデータの11テーブルは WordPressRecordController の名前）
     */
    protected const LABELS = [
        'affiliate_programs'                  => 'アフィリエイトのプログラム',
        'ai_batch_items'                      => 'AIの一括処理の対象',
        'ai_batches'                          => 'AIの一括処理',
        'ai_credit_entries'                   => 'AIのクレジットの記録',
        'ai_generations'                      => 'AIの生成',
        'ai_price_changes'                    => 'AIの料金の変更',
        'ai_price_checks'                     => 'AIの料金の確認',
        'ai_prices'                           => 'AIの料金',
        'article_draft_histories'             => '編集案の変更履歴',
        'article_drafts'                      => '編集案',
        'article_evaluation_details'          => '品質評価の項目ごとの判定',
        'article_evaluations'                 => '品質評価',
        'article_keywords'                    => '記事のキーワード',
        'article_management_histories'        => '記事の管理情報の変更履歴',
        'article_management_suggestions'      => '記事の管理情報の提案',
        'article_managements'                 => '記事の管理情報',
        'article_material_reviews'            => '記事の素材の見直し',
        'article_materials'                   => '記事と素材のつながり',
        'article_media'                       => '記事が使うメディア',
        'article_relations'                   => '記事どうしの関係',
        'author_histories'                    => '投稿者の変更履歴',
        'blog_ai_settings'                    => 'ブログごとのAIの設定',
        'blog_credentials'                    => 'ブログの認証情報',
        'blog_google_properties'              => 'ブログとGoogleのサービスのつながり',
        'blog_histories'                      => 'ブログの変更履歴',
        'blog_setting_histories'              => 'WordPressのサイト設定の変更履歴',
        'blog_settings'                       => 'WordPressのサイト設定',
        'blogs'                               => 'ブログ',
        'cache'                               => 'キャッシュ',
        'cache_locks'                         => 'キャッシュのロック',
        'category_eyecatches'                 => 'カテゴリのアイキャッチ',
        'category_histories'                  => 'カテゴリの変更履歴',
        'category_launch_children'            => 'カテゴリ立ち上げの子カテゴリ',
        'category_launches'                   => 'カテゴリ立ち上げ',
        'custom_content_histories'            => 'カスタム投稿タイプの内容の変更履歴',
        'custom_content_terms'                => 'カスタム投稿タイプの内容とカスタムタクソノミーの項目のつながり',
        'custom_term_histories'               => 'カスタムタクソノミーの項目の変更履歴',
        'database_usage_items'                => 'DBの使用量の内訳',
        'database_usage_measurements'         => 'DBの使用量の計算',
        'failed_jobs'                         => '失敗したキューの処理',
        'google_accounts'                     => 'Googleのアカウント',
        'google_adsense_dimension_daily'      => 'AdSenseの日ごとの内訳',
        'google_adsense_page_daily'           => 'AdSenseのページごとの日ごとの値',
        'google_adsense_site_daily'           => 'AdSenseのサイト全体の日ごとの値',
        'google_analytics_page_channel_daily' => 'アナリティクスのページ・チャネルごとの日ごとの値',
        'google_analytics_page_daily'         => 'アナリティクスのページごとの日ごとの値',
        'google_analytics_site_daily'         => 'アナリティクスのサイト全体の日ごとの値',
        'google_fetch_runs'                   => 'Googleのデータの取得の記録',
        'google_index_statuses'               => 'Googleのインデックスの状態',
        'google_search_console_page_daily'    => 'Search Consoleのページごとの日ごとの値',
        'google_search_console_query_daily'   => 'Search Consoleの検索語句ごとの日ごとの値',
        'google_search_console_site_daily'    => 'Search Consoleのサイト全体の日ごとの値',
        'images'                              => '画像',
        'internal_links'                      => '内部リンク',
        'job_batches'                         => 'キューの処理のまとまり',
        'jobs'                                => 'キューの処理待ち',
        'login_histories'                     => 'ログイン履歴',
        'material_categories'                 => '素材とカテゴリのつながり',
        'material_suggestions'                => '素材の提案',
        'materials'                           => '素材',
        'media_histories'                     => 'メディアの変更履歴',
        'migrations'                          => 'DBの変更（マイグレーション）の記録',
        'notices'                             => '通知',
        'page_histories'                      => '固定ページの変更履歴',
        'pagespeed_responses'                 => 'PageSpeed Insightsの応答',
        'pagespeed_runs'                      => 'PageSpeed Insightsの計測',
        'password_reset_tokens'               => 'パスワード再設定の受付',
        'post_categories'                     => '投稿とカテゴリのつながり',
        'post_histories'                      => '投稿の変更履歴',
        'post_tags'                           => '投稿とタグのつながり',
        'revision_findings'                   => '改修の指摘',
        'scheduled_task_runs'                 => '定期実行の記録',
        'scheduled_task_settings'             => '定期実行の設定',
        'sessions'                            => 'ログイン中のセッション',
        'status_histories'                    => '投稿ステータスの定義の変更履歴',
        'sync_issues'                         => '同期の問題',
        'sync_run_resources'                  => '同期の対象ごとの結果',
        'sync_runs'                           => 'WordPressとの同期の記録',
        'system_settings'                     => 'BlogOSの設定',
        'tag_histories'                       => 'タグの変更履歴',
        'taxonomy_histories'                  => 'タクソノミーの定義の変更履歴',
        'topic_suggestions'                   => '記事の題材の提案',
        'type_histories'                      => '投稿タイプの定義の変更履歴',
        'users'                               => '利用者',
        'voice_turns'                         => '音声の会話の記録',
        'wordpress_components'                => 'WordPressのプラグイン・テーマ',
        'wordpress_push_operations'           => 'WordPressへの反映記録',
    ];

    /**
     * 値を出さない列（画面では「****（説明）」と出す。WordPress API情報の Authorization と同じ形）
     */
    protected const SECRETS = [
        'users'                 => ['password' => 'パスワードのハッシュを保存', 'remember_token' => 'ログインを保つための値を保存'],
        'blog_credentials'      => ['secret' => 'Application Password を暗号化して保存'],
        'google_accounts'       => ['access_token' => 'Google のアクセストークンを暗号化して保存', 'refresh_token' => 'Google のリフレッシュトークンを暗号化して保存'],
        'sessions'              => ['payload' => 'ログイン中の情報を保存'],
        'jobs'                  => ['payload' => '処理の内容を保存'],
        'failed_jobs'           => ['payload' => '処理の内容を保存'],
        'cache'                 => ['value' => 'キャッシュの値を保存'],
        'password_reset_tokens' => ['token' => 'パスワード再設定のための値を保存'],
    ];

    /**
     * 変更履歴のテーブルを持つテーブル（テーブル => [履歴のテーブル, 履歴の元を指す列]）。
     * 取り込んだ WordPress のデータの11テーブルは、その画面で出す
     */
    protected const HISTORIES = [
        'article_drafts'      => ['article_draft_histories', 'article_draft_id'],
        'article_managements' => ['article_management_histories', 'article_management_id'],
        'blogs'               => ['blog_histories', 'blog_id'],
        'blog_settings'       => ['blog_setting_histories', 'blog_setting_id'],
    ];

    /**
     * blog_id を持つが、選択中のブログで絞らないテーブル（ブログの切り替え・登録のため、全てのブログの分を出す。利用者の判断）
     */
    protected const UNSCOPED = ['blog_ai_settings', 'blog_credentials', 'blog_google_properties', 'blog_histories', 'blog_settings', 'blog_setting_histories'];

    /**
     * リンクの文字に使う列（先にあるものを使う）
     */
    protected const TEXT_COLUMNS = ['display_name', 'name', 'title', 'title_raw', 'email', 'key', 'task_key', 'price_key', 'migration', 'slug'];

    /** @var array<string, mixed> DB から読んだテーブルの構造を使い回す（構造は migrate のときだけ変わる） */
    protected static array $memo = [];

    /**
     * BlogOS の DB のテーブル名の一覧
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::$memo['names'] ??= Schema::getTableListing((string) DB::getDatabaseName(), false);
    }

    public static function exists(string $table): bool
    {
        return in_array($table, self::names(), true);
    }

    /**
     * 日本語名（なければ null）
     */
    public static function label(string $table): ?string
    {
        return WordPressRecordController::definitions()[$table]['label'] ?? self::LABELS[$table] ?? null;
    }

    /**
     * 取り込んだ WordPress のデータの11テーブルか（一覧・詳細は、その画面を使う）
     */
    public static function isWordPress(string $table): bool
    {
        return isset(WordPressRecordController::definitions()[$table]);
    }

    /**
     * 列の一覧（name・type_name など。Schema::getColumns の形）
     *
     * @return list<array<string, mixed>>
     */
    public static function columns(string $table): array
    {
        return self::$memo['columns'][$table] ??= Schema::getColumns($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, array_column(self::columns($table), 'name'), true);
    }

    /**
     * 主キーの列（1列でなければ null。詳細へのリンクを出さない）
     */
    public static function primaryKey(string $table): ?string
    {
        if (! array_key_exists($table, self::$memo['primary'] ?? [])) {
            $columns = collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? [];
            self::$memo['primary'][$table] = count($columns) === 1 ? $columns[0] : null;
        }

        return self::$memo['primary'][$table];
    }

    /**
     * 一覧に出す列（長い文章・JSON の列は、詳細だけで出す）
     *
     * @return list<string>
     */
    public static function listColumns(string $table): array
    {
        return collect(self::columns($table))
            ->reject(fn ($column) => preg_match('/text|json|blob/i', (string) $column['type_name']))
            ->pluck('name')->values()->all();
    }

    /**
     * 絞り込みの対象の列（文字の列。秘密の値の列は除く）
     *
     * @return list<string>
     */
    public static function searchColumns(string $table): array
    {
        return collect(self::columns($table))
            ->filter(fn ($column) => in_array(strtolower((string) $column['type_name']), ['varchar', 'char'], true))
            ->pluck('name')
            ->reject(fn ($column) => self::secret($table, $column) !== null)
            ->values()->all();
    }

    /**
     * 外部キーの列（列 => つながる先のテーブル。1列で、つながる先の主キーを指すものだけ）
     *
     * @return array<string, string>
     */
    public static function foreignKeys(string $table): array
    {
        return self::$memo['foreign'][$table] ??= collect(Schema::getForeignKeys($table))
            ->filter(fn ($key) => count($key['columns']) === 1)
            ->mapWithKeys(fn ($key) => [$key['columns'][0] => $key['foreign_table']])
            ->all();
    }

    /**
     * このテーブルを外部キーで指しているテーブルと列（関連のパネル。D-80）
     *
     * @return list<array{table: string, column: string}>
     */
    public static function referencedBy(string $table): array
    {
        return collect(DB::select(
            'select TABLE_NAME as table_name, COLUMN_NAME as column_name from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = ? and REFERENCED_TABLE_NAME = ? order by TABLE_NAME, COLUMN_NAME',
            [(string) DB::getDatabaseName(), $table],
        ))->map(fn ($row) => ['table' => $row->table_name, 'column' => $row->column_name])->all();
    }

    /**
     * 選択中のブログで絞るか（blog_id があるテーブル。ブログの情報のテーブルは絞らない）
     */
    public static function scopedByBlog(string $table): bool
    {
        return self::hasColumn($table, 'blog_id') && ! in_array($table, self::UNSCOPED, true);
    }

    /**
     * 変更履歴のテーブル（[履歴のテーブル, 履歴の元を指す列]。なければ null）
     *
     * @return array{0: string, 1: string}|null
     */
    public static function history(string $table): ?array
    {
        return self::HISTORIES[$table] ?? null;
    }

    /**
     * 値を出さない列なら、その説明（なければ null）
     */
    public static function secret(string $table, string $column): ?string
    {
        return self::SECRETS[$table][$column] ?? null;
    }

    /**
     * 画面に出す値（秘密の値は「****（説明）」、_at・_gmt の日時は日本時間。D-72-04）
     */
    public static function display(string $table, string $column, mixed $value): mixed
    {
        if (($secret = self::secret($table, $column)) !== null) {
            return $value === null || $value === '' ? $value : "****（{$secret}）";
        }

        return DisplayTime::value($column, $value);
    }

    /**
     * 一覧の画面の URL（取り込んだ WordPress のデータの11テーブルは、その画面。絞り込みの条件があれば、こちらの画面で絞る）
     */
    public static function listUrl(string $table, array $query = []): string
    {
        if (self::isWordPress($table) && $query === []) {
            return route('database.wordpress-records.index', ['table' => $table]);
        }

        return route('database.tables.index', ['table' => $table] + $query);
    }

    /**
     * レコードの詳細の画面の URL
     */
    public static function recordUrl(string $table, mixed $id): string
    {
        if (self::isWordPress($table)) {
            return route('database.wordpress-records.show', ['table' => $table, 'id' => $id]);
        }

        return route('database.tables.show', ['table' => $table, 'id' => $id]);
    }

    /**
     * つながる先のレコードのリンク（{text, url}。値が空なら null。レコードがなければ、リンクにせず「（記録にない）」と出す）
     *
     * @return array{text: string, url: string|null}|null
     */
    public static function link(string $table, mixed $id): ?array
    {
        if ($id === null || $id === '' || ! self::exists($table) || ($key = self::primaryKey($table)) === null) {
            return null;
        }

        $row = DB::table($table)->where($key, $id)->first();
        if ($row === null) {
            return ['text' => "{$id}（記録にない）", 'url' => null];
        }

        $text = collect(self::TEXT_COLUMNS)->map(fn ($column) => $row->{$column} ?? null)->first(fn ($value) => $value !== null && $value !== '');
        $text = $text !== null ? \Illuminate\Support\Str::limit((string) $text, 60) : (self::label($table) ?? $table);

        return ['text' => "{$text} #{$id}", 'url' => self::recordUrl($table, $id)];
    }
}
