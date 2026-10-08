<?php

namespace App\Support;

/**
 * 取り込んだ WordPress のデータ（DB確認画面）の列の説明（D-72-08）。
 *
 * レコードの詳細の画面で、列の名前の「?」に出す。テーブルごとの説明（TABLES）があれば、そちらを優先し、なければ共通の説明（COMMON）を使う。
 * 列を足したときは、ここにも説明を足す（説明のない列は「?」を出さない）。
 */
class WordPressColumns
{
    /**
     * どのテーブルにも共通の列
     */
    protected const COMMON = [
        'id'                         => 'BlogOS の中での番号（BlogOS が付ける。WordPress の ID とは別）。',
        'blog_id'                    => 'どのブログのデータか（BlogOS のブログの番号）。',
        'wordpress_id'               => 'WordPress の中での ID（WordPress の管理画面や API で使う番号）。',
        'wordpress_date'             => 'WordPress での公開日時（WordPress のサイトのタイムゾーンの時刻）。',
        'wordpress_date_gmt'         => 'WordPress での公開日時（世界標準時。画面では日本時間に直して出す）。',
        'wordpress_modified'         => 'WordPress で最後に更新した日時（WordPress のサイトのタイムゾーンの時刻）。',
        'wordpress_modified_gmt'     => 'WordPress で最後に更新した日時（世界標準時。画面では日本時間に直して出す）。反映のときに、ほかで更新されていないかの確認に使う。',
        'synced_at'                  => 'BlogOS が WordPress から最後に取り込んだ日時。',
        'wordpress_deleted_at'       => 'WordPress 側で完全に削除されたことを、BlogOS が見つけた日時（BlogOS では消さずに残す）。',
        'created_at'                 => 'BlogOS にこの行を作った日時。',
        'updated_at'                 => 'BlogOS でこの行を最後に変えた日時。',
        'title_raw'                  => 'タイトル（WordPress で入力したそのままの文字）。',
        'title_rendered'             => 'タイトル（WordPress が表示用に整えた HTML）。',
        'content_raw'                => '本文（WordPress で入力したそのままの文字。ブロックの印を含む）。',
        'content_rendered'           => '本文（WordPress が表示用に整えた HTML）。',
        'excerpt_raw'                => '抜粋（WordPress で入力したそのままの文字）。',
        'excerpt_rendered'           => '抜粋（WordPress が表示用に整えた HTML）。',
        'meta_description_raw'       => 'メタディスクリプション（検索結果に出る説明文。入力したそのままの文字）。',
        'meta_description_rendered'  => 'メタディスクリプション（表示用に整えた文字）。',
        'slug'                       => 'スラッグ（URL の一部になる英数字の名前）。日本語は URL 用の文字（%E3…）で保存し、一覧では読める形でも出す。',
        'status'                     => '公開の状態（publish：公開、draft：下書き、pending：レビュー待ち、future：予約、private：非公開 など）。',
        'type'                       => '投稿タイプ（post：投稿、page：固定ページ など）。',
        'template'                   => 'テーマのテンプレート（空なら標準のテンプレート）。',
        'comment_status'             => 'コメントを受け付けるか（open：受け付ける、closed：受け付けない）。',
        'ping_status'                => 'ピンバック・トラックバックを受け付けるか（open／closed）。',
        'link'                       => 'WordPress での表示の URL（パーマリンク）。',
        'normalized_path'            => 'URL のパスをそろえた形（内部リンクの確認で、リンク先の記事を見つけるのに使う）。',
        'author_id'                  => '投稿者（BlogOS の投稿者の番号）。',
        'wordpress_author_id'        => '投稿者（WordPress のユーザーの ID）。',
        'featured_media_id'          => 'アイキャッチ画像（BlogOS のメディアの番号）。',
        'wordpress_featured_media_id' => 'アイキャッチ画像（WordPress のメディアの ID。0 はなし）。',
        'format'                     => '投稿フォーマット（standard：標準 など。テーマが対応している場合だけ使う）。',
        'sticky'                     => '先頭に固定表示する投稿か（1：する、0：しない）。',
        'parent_id'                  => '親（BlogOS の番号。親がなければ空）。',
        'wordpress_parent_id'        => '親（WordPress の ID。0 は親なし）。',
        'menu_order'                 => '並び順（WordPress の「順序」。小さいほど前）。',
        'name'                       => '名前（WordPress の管理画面に出る名前）。',
        'description'                => '説明（WordPress の管理画面で入力した説明）。',
    ];

    /**
     * テーブルごとの説明（共通の説明と違うもの・そのテーブルだけの列）
     */
    protected const TABLES = [
        'media' => [
            'title_raw'           => 'メディアのタイトル（入力したそのままの文字）。',
            'title_rendered'      => 'メディアのタイトル（表示用に整えた HTML）。',
            'caption_raw'         => 'キャプション（入力したそのままの文字）。',
            'caption_rendered'    => 'キャプション（表示用に整えた HTML）。',
            'description_raw'     => 'メディアの説明（入力したそのままの文字）。',
            'description_rendered' => 'メディアの説明（表示用に整えた HTML）。',
            'alt_text'            => '代替テキスト（画像が表示できないときや、画面の読み上げで使う文字）。',
            'source_url'          => 'ファイルそのものの URL。',
            'mime_type'           => 'ファイルの種類（image/png・image/jpeg など）。',
            'media_type'          => 'メディアの種類（image：画像、file：そのほかのファイル）。',
            'width'               => '画像の横の大きさ（ピクセル）。',
            'height'              => '画像の縦の大きさ（ピクセル）。',
            'filesize'            => 'ファイルの大きさ（バイト）。',
            'sizes'               => 'WordPress が作った大きさ違いの画像（サムネイルなど）の一覧（JSON）。',
            'status'              => 'メディアの状態（inherit：添付先の投稿の状態に従う など）。',
            'wordpress_post_id'   => '添付先の投稿・固定ページ（WordPress の ID。0 はどこにも添付していない）。',
            'post_id'             => '添付先の投稿（BlogOS の投稿の番号）。',
            'page_id'             => '添付先の固定ページ（BlogOS の固定ページの番号）。',
        ],
        'categories' => [
            'name'        => 'カテゴリの名前。',
            'description' => 'カテゴリの説明。',
            'link'        => 'カテゴリの記事の一覧の URL。',
            'parent_id'   => '親カテゴリ（BlogOS のカテゴリの番号。親がなければ空）。',
            'wordpress_parent_id' => '親カテゴリ（WordPress の ID。0 は親なし）。',
        ],
        'tags' => [
            'name'        => 'タグの名前。',
            'description' => 'タグの説明。',
            'link'        => 'タグの記事の一覧の URL。',
        ],
        'authors' => [
            'name'        => '投稿者の表示名。',
            'url'         => '投稿者のプロフィールに登録したサイトの URL。',
            'description' => '投稿者のプロフィールの説明。',
            'link'        => '投稿者の記事の一覧の URL。',
            'avatar_urls' => 'プロフィール画像の URL（大きさごと。JSON）。',
            'roles'       => 'WordPress での権限（administrator：管理者、editor：編集者 など。JSON）。',
        ],
        'pages' => [
            'parent_id'           => '親ページ（BlogOS の固定ページの番号。親がなければ空）。',
            'wordpress_parent_id' => '親ページ（WordPress の ID。0 は親なし）。',
        ],
        'custom_contents' => [
            'type' => 'カスタム投稿タイプの名前（どの種類の内容か）。',
        ],
        'custom_terms' => [
            'taxonomy'    => 'カスタムタクソノミーの名前（どの分類の項目か）。',
            'name'        => '項目の名前。',
            'description' => '項目の説明。',
            'link'        => '項目の記事の一覧の URL。',
        ],
        'statuses' => [
            'slug'         => '投稿ステータスの名前（publish・draft など。WordPress の中で使う名前）。',
            'name'         => '投稿ステータスの表示名。',
            'public'       => 'サイトを見る人に公開する状態か（1：公開、0：非公開）。',
            'queryable'    => 'サイトの URL で表示できる状態か（1：できる、0：できない）。',
            'show_in_list' => 'WordPress の管理画面の一覧に、この状態の絞り込みを出すか（1：出す、0：出さない）。',
        ],
        'types' => [
            'slug'           => '投稿タイプの名前（post・page など。WordPress の中で使う名前）。',
            'name'           => '投稿タイプの表示名。',
            'description'    => '投稿タイプの説明。',
            'hierarchical'   => '親子の関係を持てるか（1：持てる。固定ページなど、0：持てない。投稿など）。',
            'rest_base'      => 'WordPress の API で、この投稿タイプを取得するときの名前（/wp-json/wp/v2/{名前}）。',
            'rest_namespace' => 'WordPress の API の名前空間（wp/v2 など）。',
            'taxonomies'     => 'この投稿タイプで使う分類（category・post_tag など。JSON）。',
        ],
        'taxonomies' => [
            'slug'           => '分類（タクソノミー）の名前（category・post_tag など。WordPress の中で使う名前）。',
            'name'           => '分類の表示名。',
            'description'    => '分類の説明。',
            'hierarchical'   => '親子の関係を持てるか（1：持てる。カテゴリなど、0：持てない。タグなど）。',
            'rest_base'      => 'WordPress の API で、この分類を取得するときの名前（/wp-json/wp/v2/{名前}）。',
            'rest_namespace' => 'WordPress の API の名前空間（wp/v2 など）。',
            'types'          => 'この分類を使う投稿タイプ（post など。JSON）。',
        ],
    ];

    /**
     * 列の説明（なければ null）
     */
    public static function describe(string $table, string $column): ?string
    {
        return self::TABLES[$table][$column] ?? self::COMMON[$column] ?? null;
    }
}
