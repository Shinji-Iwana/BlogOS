<?php

namespace App\Support;

/**
 * WordPress API情報の画面の「詳細」の表（D-72-10）。
 *
 * 1件のデータの応答（投稿の詳細・API Root・Settings など）を、項目ごとの行に並べる（wordpress-api/fields）。
 * 入れ子の項目（例：_links → self → 0 → href）は、まとまりの名前の行を押すと、中の項目の表が開く（項目名の列が長くならないように）。
 * 値が数字・文字だけの一覧は、1行に「、」でつないで出す（leaf）。API Root の routes（全ての API の道筋と引数）は量が多いため、まとめて1行（JSON）で出す。
 * 説明は、入れ子の道筋を「.」でつないだ名前（例：_links.self.0.href）で探す。
 * 項目名の「?」に、何を保持する項目かの説明を出す（describe。項目ごと・まとまりごとの説明。説明がない項目は「?」を出さない）。
 */
class WordPressApiFields
{
    /**
     * まとめて1行（JSON）で出す項目（量が多いもの）
     */
    protected const WHOLE = ['routes'];

    /**
     * どの種類の応答にも共通の項目。「*」は、そのまとまりの中の全ての項目
     */
    protected const COMMON = [
        'id'                    => 'WordPress の中での ID。',
        'date'                  => '公開日時（WordPress のサイトのタイムゾーンの時刻）。',
        'date_gmt'              => '公開日時（世界標準時。日本時間に直して出す）。',
        'modified'              => '最後に更新した日時（WordPress のサイトのタイムゾーンの時刻）。',
        'modified_gmt'          => '最後に更新した日時（世界標準時。日本時間に直して出す）。',
        'guid'                  => 'WordPress が付ける、変わらない識別用の URL。',
        'guid.raw'              => 'WordPress が付ける、変わらない識別用の URL（そのままの値）。',
        'guid.rendered'         => 'WordPress が付ける、変わらない識別用の URL（表示用）。',
        'slug'                  => 'スラッグ（URL の一部になる英数字の名前）。',
        'generated_slug'        => 'スラッグを空にしたときに、WordPress が付けるスラッグ。',
        'status'                => '公開の状態（publish：公開、draft：下書き、pending：レビュー待ち、future：予約、private：非公開 など）。',
        'type'                  => '投稿タイプ（post：投稿、page：固定ページ など）。',
        'link'                  => '表示の URL（パーマリンク）。',
        'permalink_template'    => 'パーマリンクのひな形（%postname% などの置き換え前）。',
        'password'              => '閲覧のパスワード（パスワードで保護したときだけ）。',
        'title'                 => 'タイトル。',
        'title.raw'             => 'タイトル（入力したそのままの文字）。',
        'title.rendered'        => 'タイトル（表示用に整えた HTML）。',
        'content'               => '本文。',
        'content.raw'           => '本文（入力したそのままの文字。ブロックの印を含む）。',
        'content.rendered'      => '本文（表示用に整えた HTML）。',
        'content.protected'     => 'パスワードで保護しているか。',
        'content.block_version' => '本文で使っているブロックの書き方の版。',
        'excerpt'               => '抜粋。',
        'excerpt.raw'           => '抜粋（入力したそのままの文字）。',
        'excerpt.rendered'      => '抜粋（表示用に整えた HTML）。',
        'excerpt.protected'     => 'パスワードで保護しているか。',
        'author'                => '投稿者（WordPress のユーザーの ID）。',
        'featured_media'        => 'アイキャッチ画像（WordPress のメディアの ID。0 はなし）。',
        'comment_status'        => 'コメントを受け付けるか（open／closed）。',
        'ping_status'           => 'ピンバック・トラックバックを受け付けるか（open／closed）。',
        'sticky'                => '先頭に固定表示する投稿か。',
        'template'              => 'テーマのテンプレート（空なら標準）。',
        'format'                => '投稿フォーマット（standard：標準 など）。',
        'meta'                  => 'カスタムフィールド（プラグイン・テーマ・BlogOS の拡張が使う値）。',
        'meta.*'                => 'カスタムフィールドの値（プラグイン・テーマ・BlogOS の拡張が使う）。',
        'categories'            => 'カテゴリ（WordPress のカテゴリの ID の一覧）。',
        'tags'                  => 'タグ（WordPress のタグの ID の一覧）。',
        'class_list'            => 'テーマが記事に付ける HTML の class の一覧。',
        'class_list.*'          => 'テーマが記事に付ける HTML の class。',
        'parent'                => '親（WordPress の ID。0 は親なし）。',
        'menu_order'            => '並び順（小さいほど前）。',
        'name'                  => '名前。',
        'description'           => '説明。',
        'count'                 => 'この分類が付いている公開中の記事の数。',
        'taxonomy'              => '分類（タクソノミー）の名前（category・post_tag など）。',
        '_links'                => '関連する API の URL（WordPress が付ける）。',
        '_links.*'              => '関連する API の URL（WordPress が付ける。self：このデータ、collection：一覧、author：投稿者 など）。',
        '_embedded'             => '一緒に取得した関連のデータ。',
        '_embedded.*'           => '一緒に取得した関連のデータ。',
    ];

    /**
     * 応答の種類（ApiInspectionService::RESOURCES のキー）ごとの説明（共通の説明と違うもの・その種類だけの項目）
     */
    protected const RESOURCES = [
        'root' => [
            'name'            => 'サイトのタイトル。',
            'description'     => 'サイトのキャッチフレーズ。',
            'url'             => 'WordPress のアドレス（WordPress を置いている URL）。',
            'home'            => 'サイトのアドレス（サイトを見る人が開く URL）。',
            'gmt_offset'      => '世界標準時との時差（時間）。',
            'timezone_string' => 'サイトのタイムゾーン（Asia/Tokyo など）。',
            'page_for_posts'  => '投稿の一覧を出す固定ページ（WordPress の ID。0 はなし）。',
            'page_on_front'   => 'トップページに出す固定ページ（WordPress の ID。0 はなし）。',
            'show_on_front'   => 'トップページに出すもの（posts：最新の投稿、page：固定ページ）。',
            'namespaces'      => '使える API の名前空間の一覧（wp/v2 など。プラグインが足したものを含む）。',
            'namespaces.*'    => '使える API の名前空間（wp/v2 など）。',
            'authentication'  => '使える認証の方法（Application Password など）。',
            'authentication.*' => '使える認証の方法の情報。',
            'routes'          => '全ての API の道筋と、受け付ける引数（量が多いため、まとめて出す）。',
            'site_logo'       => 'サイトのロゴ（WordPress のメディアの ID）。',
            'site_icon'       => 'サイトのアイコン（WordPress のメディアの ID）。',
            'site_icon_url'   => 'サイトのアイコンの URL。',
        ],
        'settings' => [
            'title'                  => 'サイトのタイトル。',
            'description'            => 'サイトのキャッチフレーズ。',
            'url'                    => 'サイトのアドレス。',
            'email'                  => '管理者のメールアドレス。',
            'timezone'               => 'サイトのタイムゾーン。',
            'date_format'            => '日付の書式。',
            'time_format'            => '時刻の書式。',
            'start_of_week'          => '週の始まりの曜日（0：日曜日）。',
            'language'               => 'サイトの言語。',
            'use_smilies'            => '顔文字を画像に変えるか。',
            'default_category'       => '投稿の標準のカテゴリ（WordPress の ID）。',
            'default_post_format'    => '投稿の標準のフォーマット。',
            'posts_per_page'         => '1ページに出す投稿の数。',
            'show_on_front'          => 'トップページに出すもの（posts：最新の投稿、page：固定ページ）。',
            'page_on_front'          => 'トップページに出す固定ページ（WordPress の ID。0 はなし）。',
            'page_for_posts'         => '投稿の一覧を出す固定ページ（WordPress の ID。0 はなし）。',
            'default_ping_status'    => '新しい投稿で、ピンバック・トラックバックを受け付けるか。',
            'default_comment_status' => '新しい投稿で、コメントを受け付けるか。',
            'site_logo'              => 'サイトのロゴ（WordPress のメディアの ID）。',
            'site_icon'              => 'サイトのアイコン（WordPress のメディアの ID）。',
        ],
        'media' => [
            'title'                        => 'メディアのタイトル。',
            'title.raw'                    => 'メディアのタイトル（入力したそのままの文字）。',
            'title.rendered'               => 'メディアのタイトル（表示用に整えた HTML）。',
            'alt_text'                     => '代替テキスト（画像が表示できないときや、画面の読み上げで使う文字）。',
            'caption'                      => 'キャプション。',
            'caption.raw'                  => 'キャプション（入力したそのままの文字）。',
            'caption.rendered'             => 'キャプション（表示用に整えた HTML）。',
            'description'                  => 'メディアの説明。',
            'description.raw'              => 'メディアの説明（入力したそのままの文字）。',
            'description.rendered'         => 'メディアの説明（表示用に整えた HTML）。',
            'media_type'                   => 'メディアの種類（image：画像、file：そのほかのファイル）。',
            'mime_type'                    => 'ファイルの種類（image/png など）。',
            'media_details'                => 'ファイルの詳しい情報（大きさ・大きさ違いの画像・撮影の情報など）。',
            'media_details.*'              => 'ファイルの詳しい情報（width・height：大きさ、file：ファイルの場所、filesize：大きさ（バイト）、sizes：大きさ違いの画像、image_meta：撮影の情報）。',
            'post'                         => '添付先の投稿・固定ページ（WordPress の ID。なければ空）。',
            'source_url'                   => 'ファイルそのものの URL。',
            'missing_image_sizes'          => '作れなかった大きさ違いの画像の一覧。',
            'missing_image_sizes.*'        => '作れなかった大きさ違いの画像。',
            'status'                       => 'メディアの状態（inherit：添付先の投稿の状態に従う など）。',
        ],
        'categories' => [
            'name'        => 'カテゴリの名前。',
            'description' => 'カテゴリの説明。',
            'link'        => 'カテゴリの記事の一覧の URL。',
            'parent'      => '親カテゴリ（WordPress の ID。0 は親なし）。',
        ],
        'tags' => [
            'name'        => 'タグの名前。',
            'description' => 'タグの説明。',
            'link'        => 'タグの記事の一覧の URL。',
        ],
        'users' => [
            'name'               => '表示名。',
            'url'                => 'プロフィールに登録したサイトの URL。',
            'description'        => 'プロフィールの説明。',
            'link'               => 'このユーザーの記事の一覧の URL。',
            'avatar_urls'        => 'プロフィール画像の URL（大きさごと）。',
            'avatar_urls.*'      => 'プロフィール画像の URL（数字は大きさ（ピクセル））。',
            'username'           => 'ログインの名前。',
            'first_name'         => '名。',
            'last_name'          => '姓。',
            'email'              => 'メールアドレス。',
            'locale'             => 'ユーザーの言語。',
            'nickname'           => 'ニックネーム。',
            'registered_date'    => '登録した日時（日本時間に直して出す）。',
            'roles'              => '権限（administrator：管理者、editor：編集者 など）。',
            'roles.*'            => '権限。',
            'capabilities'       => 'できる操作の一覧（true：できる）。',
            'capabilities.*'     => 'できる操作（true：できる）。',
            'extra_capabilities' => '権限のほかに個別に付けた、できる操作。',
            'extra_capabilities.*' => '権限のほかに個別に付けた、できる操作。',
        ],
        'statuses' => [
            'name'          => '状態の表示名。',
            'slug'          => '状態の名前（publish・draft など）。',
            'private'       => '非公開の状態か。',
            'protected'     => '保護された状態か（ログインした人だけが見られる）。',
            'public'        => 'サイトを見る人に公開する状態か。',
            'queryable'     => 'サイトの URL で表示できる状態か。',
            'show_in_list'  => 'WordPress の管理画面の一覧に、この状態の絞り込みを出すか。',
            'date_floating' => '日時を決めずに保存する状態か（下書きなど）。',
        ],
        'types' => [
            'name'           => '投稿タイプの表示名。',
            'slug'           => '投稿タイプの名前（post・page など）。',
            'description'    => '投稿タイプの説明。',
            'hierarchical'   => '親子の関係を持てるか。',
            'viewable'       => 'サイトで表示できるか。',
            'has_archive'    => '一覧のページがあるか（あれば、その URL の一部）。',
            'capabilities'   => 'この投稿タイプの操作に要る権限の名前。',
            'capabilities.*' => 'この投稿タイプの操作に要る権限の名前。',
            'labels'         => '管理画面に出す文言。',
            'labels.*'       => '管理画面に出す文言。',
            'supports'       => '使える機能（タイトル・本文・アイキャッチ画像など）。',
            'supports.*'     => '使える機能（true：使える）。',
            'taxonomies'     => 'この投稿タイプで使う分類の一覧（category・post_tag など）。',
            'taxonomies.*'   => 'この投稿タイプで使う分類。',
            'rest_base'      => 'API で、この投稿タイプを取得するときの名前（/wp-json/wp/v2/{名前}）。',
            'rest_namespace' => 'API の名前空間（wp/v2 など）。',
            'visibility'     => '管理画面での見せ方。',
            'visibility.*'   => '管理画面での見せ方（show_ui：管理画面に出す、show_in_nav_menus：メニューに出す）。',
            'icon'           => '管理画面のメニューのアイコン。',
            'template'       => 'ブロックエディターで初めに入れるブロックのひな形。',
            'template.*'     => 'ブロックエディターで初めに入れるブロックのひな形。',
            'template_lock'  => 'ひな形のブロックを動かせないようにするか。',
        ],
        'taxonomies' => [
            'name'           => '分類の表示名。',
            'slug'           => '分類の名前（category・post_tag など）。',
            'description'    => '分類の説明。',
            'hierarchical'   => '親子の関係を持てるか（カテゴリは持てる、タグは持てない）。',
            'show_cloud'     => 'タグクラウドに出すか。',
            'types'          => 'この分類を使う投稿タイプの一覧。',
            'types.*'        => 'この分類を使う投稿タイプ。',
            'capabilities'   => 'この分類の操作に要る権限の名前。',
            'capabilities.*' => 'この分類の操作に要る権限の名前。',
            'labels'         => '管理画面に出す文言。',
            'labels.*'       => '管理画面に出す文言。',
            'rest_base'      => 'API で、この分類を取得するときの名前（/wp-json/wp/v2/{名前}）。',
            'rest_namespace' => 'API の名前空間（wp/v2 など）。',
            'visibility'     => '管理画面での見せ方。',
            'visibility.*'   => '管理画面での見せ方（public：公開、show_ui：管理画面に出す など）。',
        ],
    ];

    /**
     * 項目の値を、表の1行に出す文字にする。入れ子のまとまり（中にさらに項目がある）なら null（押して開く行にする）。
     * 空のまとまりは「（なし）」、値が数字・文字だけの一覧は「、」でつないで1行、まとめて出す項目（routes）は JSON で1行
     *
     * @param  string  $path  項目の道筋（「.」でつなぐ。説明を探すのと、まとめて出す項目の判断に使う）
     */
    public static function leaf(string $path, mixed $value): ?string
    {
        return match (true) {
            ! is_array($value)                     => self::scalar($value),
            $value === []                          => '（なし）',
            in_array($path, self::WHOLE, true)     => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            array_is_list($value) && array_filter($value, 'is_array') === [] => implode('、', array_map(fn ($item) => self::scalar($item), $value)),
            default                                => null,
        };
    }

    /**
     * 値を表に出す文字にする（true・false・null は、そのままの言葉で）
     */
    public static function scalar(mixed $value): string
    {
        return match (true) {
            $value === true  => 'true',
            $value === false => 'false',
            $value === null  => 'null',
            default          => (string) $value,
        };
    }

    /**
     * 項目の説明（なければ null）。その項目の説明がなければ、上のまとまりの「.*」の説明を使う（例：_links.self.0.href → _links.*）
     */
    public static function describe(string $resource, string $path): ?string
    {
        $segments = explode('.', $path);
        $candidates = [implode('.', array_map(fn ($segment) => ctype_digit($segment) ? '*' : $segment, $segments))];
        $candidates[] = $path;
        for ($i = count($segments) - 1; $i >= 1; $i--) {
            $candidates[] = implode('.', array_slice($segments, 0, $i)) . '.*';
        }

        foreach ($candidates as $candidate) {
            $note = self::RESOURCES[$resource][$candidate] ?? self::COMMON[$candidate] ?? null;
            if ($note !== null) {
                return $note;
            }
        }

        return null;
    }
}
