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
     * WordPress 本体のテーブルの日本語名（接頭辞を除いた名前 => 日本語名）
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
    ];

    /**
     * 値を出さない列（接頭辞を除いた名前 => [列 => 説明]）
     */
    protected const SECRETS = [
        'users' => ['user_pass' => 'パスワードのハッシュを保存', 'user_activation_key' => 'パスワード再設定のための値を保存'],
        'posts' => ['post_password' => '記事を読むためのパスワードを保存'],
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
    protected const TEXT_COLUMNS = ['post_title', 'display_name', 'user_login', 'name', 'option_name', 'meta_key', 'taxonomy', 'comment_author', 'link_name'];

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
