# BlogOS DB設計書

**バージョン:** 2.0.0
**最終更新日:** 2026-09-24
**主な変更:** 2026-09-23 設計監査の決定事項（`BLOGOS_DECISIONS.md` の D-01〜D-12）を反映

## 1. 本書の目的

本書は、BlogOSが管理するデータをどのようにDBへ保存し、各データをどのような関係で管理するかを定義する。

本書では、BlogOSの理想的なDB設計を定義する。現在のMigration、Model、Repository等の実装内容によって、本書の設計を制限してはならない。現在の実装との差異の扱いは `CLAUDE.md` に従う。

本書は、以下の規則を定義する唯一の場所とする（D-12-03）。

* ID・列の命名規則（3章）
* 履歴の構造と変更元の列挙値（8章）
* 削除の扱い（13章）
* 保存期間（14章）

参照元と正本の区分は `BLOGOS_ARCHITECTURE.md` 3-2 で定義する。具体的なLaravelでの実装方法は `BLOGOS_DEVELOPMENT_RULES.md` で定義する。

本書に記載する列は主要なものである。データ型・NULL可否・Default値の最終確定と、WordPress APIの各フィールドを保存するかどうかの細部は、実装時に本書の規則に従って決定し、本書へ反映する。

本文中の `（D-xx-xx）` は、根拠となる決定事項（`BLOGOS_DECISIONS.md`）を示す。

---

# 2. DB設計の基本方針

## 2-1. BlogOS DBの役割

BlogOS DBは、BlogOSが扱う情報の参照元である（ARCHITECTURE 3-2）。

## 2-2. テーブルの分類

| 分類 | 内容 | 更新するもの |
| --- | --- | --- |
| WordPress由来のテーブル | WordPress APIの取得結果の写し。WordPress由来の列だけを持つ | 同期・反映の結果だけ |
| BlogOS独自のテーブル | 編集案、記事の管理情報、評価、AI実行記録など | BlogOSの操作・AI |
| 記録のテーブル | 履歴、同期の記録、反映記録 | 各処理 |

同期処理は、WordPress由来のテーブルだけを更新し、BlogOS独自のテーブルを変更しない（D-01-11）。

## 2-3. APIレスポンスとDBを同一視しない

WordPress APIから取得できる項目と、BlogOS DBへ保存する項目は同一とは限らない。APIで取得できるという理由だけで、すべてを保存しない。

APIレスポンスの原文はDBに保存しない。ただし、同期・反映でエラーになった場合の応答本文は全文を保存する（D-10-03、10章）。

## 2-4. 複数ブログを前提とする

ブログに属するデータは、`blog_id` によってどのブログのデータかを識別する。異なるブログのデータ同士を関連付けない（例：`post.blog_id = category.blog_id` を保証する）。

---

# 3. 共通規則

## 3-1. 主キー

すべてのテーブルで、自動採番の `id`（bigint）を主キーとする。外部に渡す識別子が必要なものだけ、別の列として `uuid` を持つ（D-02-01）。

`id` は常にBlogOS内部のIDとし、WordPress IDを入れてはならない（D-02-02）。

## 3-2. WordPress IDと参照の列名

（D-02-02、D-02-03、D-05-03）

| 対象 | 列名 | 例 |
| --- | --- | --- |
| そのテーブル自身のWordPress ID | `wordpress_id` | `posts.wordpress_id` |
| 他のWordPressデータを参照するWordPress ID | `wordpress_<APIのフィールド名>_id` | `wordpress_author_id`、`wordpress_parent_id`、`wordpress_featured_media_id`、`wordpress_post_id` |
| 他のデータを参照する内部の外部キー（APIのフィールドに対応するもの） | `<APIのフィールド名>_id` | `author_id`、`parent_id`、`featured_media_id` |
| ブログ・多対多の中間テーブル・BlogOS独自の参照 | `<テーブル名の単数形>_id` | `blog_id`、`post_id`、`category_id`、`article_draft_id` |

* WordPress IDは受け取ったとおりに必ず保存し、差分チェックとWordPressへの反映に使う。
* 内部の外部キーは、参照先がDBに存在すれば設定し、存在しなければNULLとする。解決できない参照は同期の問題（`sync_issues`）に記録する。
* BlogOS独自のテーブルは、内部IDだけで参照する。

## 3-3. 数値IDを持たないデータ

statuses・types・taxonomies は、`blog_id + slug` を一意キーとする。列名はWordPress APIに合わせて `slug` とする（D-02-04）。

## 3-4. `blog_id` の意味

`blog_id` は常に `blogs.id`（BlogOS内部のID）を指す。WordPressマルチサイトのサイトIDが必要になった場合は `wordpress_site_id` と呼ぶ（D-02-06）。

## 3-5. 記事の参照

「記事」は投稿（posts）と固定ページ（pages）を指す（ARCHITECTURE 17-1）。

記事を参照するテーブルは、`post_id` と `page_id` をどちらもNULL許容で持ち、「どちらか一方だけに値が入る」CHECK制約を付ける（D-08-01）。新規記事の案など、まだ記事が存在しない場合を許すテーブルでは「多くとも一方」とする（各テーブルで明記する）。

ポリモーフィック関連（`xxx_type` / `xxx_id`）は、外部キー制約を付けられないため使わない。

## 3-6. WordPress由来のテーブルに共通の列

| 列 | 内容 |
| --- | --- |
| `blog_id` | ブログ |
| `wordpress_id` | WordPress ID（数値IDを持つ場合） |
| `wordpress_modified_gmt` | WordPressの最終更新日時（WordPressが返す場合）。差分判定と競合チェックに使う |
| `synced_at` | 最後にWordPressと照合した日時 |
| `wordpress_deleted_at` | WordPress側で完全削除を検知した日時（13章） |
| `created_at` / `updated_at` | BlogOS側でレコードを作成・更新した日時（Laravel標準） |

レコード単位の同期状態を表す列（`sync_status` 等）は持たない。問題の有無は `sync_issues` で判断する（D-04-03）。

## 3-7. 日時の列

WordPressの日時は `wordpress_date` / `wordpress_date_gmt` / `wordpress_modified` / `wordpress_modified_gmt` とする。比較と並べ替えにはGMTの値を使う（D-05-08）。

## 3-8. 本文の列

title・content・excerpt など、`context=edit` で `raw` と `rendered` の両方が返る項目は、`<項目>_raw` と `<項目>_rendered` の2列で保存する。差分判定と履歴は `raw` で行う（D-05-07）。

## 3-9. 列挙値

変更元・状態・種類などの列挙値は、DBでは文字列型とし、PHPのBacked Enumで値を管理する。DBのenum型は使わない（D-02-07）。

## 3-10. JSON列

JSON列は、検索・集計・関連付けに使わない補助的な情報（例：`avatar_urls`、メディアの `sizes`）に限って使う。検索・関連付けに使う情報は、テーブル・列に正規化する。

---

# 4. テーブル全体像

**ログイン**

* `users`（Laravel標準。BlogOSの利用者）
* `login_histories`（ログイン・ログアウトの記録）

**ブログ**

* `blogs`
  * `blog_histories`
* `blog_credentials`
* `blog_settings`
  * `blog_setting_histories`

**WordPress由来**

* `posts` ─ `post_histories`
  * `post_categories`
  * `post_tags`
* `pages` ─ `page_histories`
* `categories` ─ `category_histories`
* `tags` ─ `tag_histories`
* `authors` ─ `author_histories`
* `media` ─ `media_histories`
* `statuses` ─ `status_histories`
* `types` ─ `type_histories`
* `taxonomies` ─ `taxonomy_histories`
* `custom_contents` ─ `custom_content_histories`
  * `custom_content_terms`
* `custom_terms` ─ `custom_term_histories`

**本文から抽出するもの**

* `internal_links`
* `article_media`

**BlogOS独自（記事）**

* `article_drafts` ─ `article_draft_histories`
* `article_managements` ─ `article_management_histories`
* `article_keywords`
* `article_relations`
* `article_evaluations`
  * `article_evaluation_details`
* `ai_generations`

**記録**

* `sync_runs`
  * `sync_run_resources`
* `sync_issues`
* `wordpress_push_operations`

**Google**

* `google_accounts`
* `blog_google_properties`
* Google指標のテーブル（Google連携の設計時に定義。12章）

---

# 5. ログイン・ブログ

## 5-1. users

BlogOSにログインする利用者（Laravel標準）。現時点では1人で、新規登録画面は設けずSeederで登録する（D-03-01）。

WordPressのユーザーとは別物であり、WordPressのユーザーは `authors`（6-5）に保存する。

* 管理者の登録は `AdminUserSeeder` で行い、メールアドレス・パスワードは環境変数（`ADMIN_EMAIL`・`ADMIN_PASSWORD`）から読む。ソースコードに書かない（D-17-01）。
* テスト用・初期状態のユーザー（Laravelの初期状態の Test User 等）は、どの環境でも作成しない（D-17-02）。

### login_histories

BlogOSへのログイン・ログアウトの記録（D-17-03）。不正なログインの有無を確認するために使う。

| 列 | 内容 |
| --- | --- |
| `user_id` | ログインした利用者（`users.id`）。失敗した場合など、該当する利用者がいない場合はNULL |
| `email` | 入力されたメールアドレス（失敗した場合も記録する） |
| `event` | `login_succeeded` / `login_failed` / `login_locked`（試行回数の制限によって止めた試行。D-17-06） / `logout` |
| `ip_address` | 接続元のIPアドレス |
| `user_agent` | ブラウザの情報 |
| `occurred_at` | 発生日時 |

* パスワードは、成功・失敗にかかわらず記録しない。
* 利用者を削除した場合、`user_id` は SET NULL とし、記録は残す。

---

## 5-2. blogs

BlogOSが管理するブログ。BlogOS側で管理する情報だけを持つ（D-10-01）。

| 列 | 内容 |
| --- | --- |
| `id` | ブログのID |
| `home` | ブログのホームURL（正規化済み）。WordPress APIの接続先とブログの識別に使う |
| `display_name` | BlogOS上での表示名 |
| `quality_profile` | 適用する品質基準のブログ別の定義の識別子（`resources/quality/blogs/{識別子}`）（D-06-10） |
| `is_selected` | 選択中かどうか（D-02-05） |
| `archived_at` | アーカイブした日時（D-09-06） |
| `created_at` / `updated_at` | ― |

* `home` はUNIQUEとする（同じブログの二重登録を防ぐ。完全削除の確認にも使う）（D-13-01）。
* `home` には、WordPressの「サイトアドレス」（`home`）を使う。「WordPressアドレス」（`url`、WordPress本体の設置場所）は使わない。WordPressのREST APIのURLはサイトアドレスを基準に作られるためである。
* 登録時は、利用者が入力したURLからAPI Discoveryを行い、API Rootが返す `home` を正規化して保存する。
* 正規化では、スキーム（`http` / `https`）の違い、ホスト名の大文字・小文字、末尾のスラッシュを吸収し、ホスト名とパスで比較する。
* 同期で `blog_settings` の `home` の変更を検知した場合は、`blogs.home` を自動では更新しない。`sync_issues` に登録し、人が確認して更新する（接続先が意図せず変わることを防ぐため）。
* `is_selected` が真のブログは常に1件以下とする。切り替えは1つのトランザクションで行う。
* 同期状態・最終同期日時の列は持たない。直近の `sync_runs` から判断する（D-04-04）。

### blog_histories

`blogs` の変更履歴（主な変更元は `blogos_manual`）。構造は8章。

---

## 5-3. blog_credentials

ブログごとのWordPress認証情報（D-03-02）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ブログ（UNIQUE） |
| `auth_type` | 認証方式（`application_password`） |
| `username` | WordPressのユーザー名 |
| `secret` | Application Password（Laravelの `encrypted` キャストで暗号化。TEXT型） |
| `verified_at` | 最後に接続確認に成功した日時 |
| `connector_extension` | WordPress側の拡張（投稿メタ `_blogos_draft_id`）が有効か。登録時と接続確認時に判定する。NULLは判定できなかった場合（D-20-03、WORDPRESS_API 26章） |
| `last_failed_at` / `last_error` | 最後に失敗した日時と内容（認証情報そのものは含めない） |

* `blogs` と同じテーブルに置かない（一覧取得などで誤って出力することを防ぐ）。
* Modelでは `secret` を配列化・JSON化の対象から外す。
* 暗号化の鍵（`APP_KEY`）は、DBのバックアップとは別に保管する。

---

## 5-4. blog_settings

WordPressのサイト設定（WordPress由来）。キーと値の形で保存する（D-10-01）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ブログ |
| `key` | 設定のキー |
| `value` | 値（文字列。JSONが必要なものはJSON文字列） |
| `synced_at` | ― |

* 一意キー：`blog_id + key`
* 保存するキー：`title`、`description`、`url`、`home`、`timezone_string`、`gmt_offset`、`date_format`、`time_format`、`language`、`posts_per_page`、`show_on_front`、`page_on_front`、`page_for_posts`、`default_category`、`site_icon`、`site_logo`
* 上記以外のキーは保存しない（キーの追加は本書の更新で行う）。

### blog_setting_histories

`blog_settings` の変更履歴。キーごとの変更が、8章の「変更した項目ごとに1行」にそのまま対応する。

---

# 6. WordPress由来のテーブル

すべて 3-6 の共通の列を持つ。一意キーは `blog_id + wordpress_id`（statuses・types・taxonomies は `blog_id + slug`）。

## 6-1. posts

| 分類 | 列 |
| --- | --- |
| 本文 | `title_raw` / `title_rendered`、`content_raw` / `content_rendered`、`excerpt_raw` / `excerpt_rendered` |
| 基本 | `slug`、`status`（ゴミ箱の `trash` を含む）、`type`、`format`、`sticky`、`comment_status`、`ping_status`、`template` |
| URL | `link`、`normalized_path`（`link` からドメイン・末尾のスラッシュ・クエリを除いたもの。Googleデータとの対応付けに使う。D-08-05） |
| 参照 | `author_id` / `wordpress_author_id`、`featured_media_id` / `wordpress_featured_media_id` |
| 日時 | `wordpress_date` / `wordpress_date_gmt`、`wordpress_modified` / `wordpress_modified_gmt` |

* `status`・`type` は、APIの値をそのまま文字列で保存し、`statuses`・`types` への外部キーは持たない（D-05-06）。
* `wordpress_id` は必須（NOT NULL）。WordPressに未作成の記事は `article_drafts` だけで管理する（D-01-07）。

### post_categories / post_tags

投稿とカテゴリ・タグの多対多の関係。

| 列 | 内容 |
| --- | --- |
| `post_id` | 投稿 |
| `category_id`（または `tag_id`） | カテゴリ（タグ） |

* 一意キー：`post_id + category_id`（`post_id + tag_id`）
* 関連の変更は、`post_histories` に項目名 `categories` / `tags` として記録する（変更前後の値はWordPress IDの一覧）。

---

## 6-2. pages

`posts` と同じ構成に、以下を加える（カテゴリ・タグ・`sticky`・`format` は持たない）。

| 列 | 内容 |
| --- | --- |
| `parent_id` / `wordpress_parent_id` | 親ページ |
| `menu_order` | 並び順 |

---

## 6-3. categories

| 列 | 内容 |
| --- | --- |
| `name`、`slug`、`description`、`link` | ― |
| `parent_id` / `wordpress_parent_id` | 親カテゴリ |

* WordPressの `count`（投稿数）は保存しない。`post_categories` から算出できるため、また投稿が増えるたびに履歴が増えることを避けるため。

---

## 6-4. tags

`categories` と同じ構成（親は持たない）。

---

## 6-5. authors

WordPressのユーザー。Users APIが返すユーザーを全員保存する（D-05-01）。テーブル名はBlogOSの `users` と区別するため `authors` とする。

| 列 | 内容 |
| --- | --- |
| `name`（表示名）、`slug`、`url`、`description`、`link` | ― |
| `avatar_urls` | JSON |
| `roles` | JSON |

* `username`（ログイン名）、`email`、`capabilities` は保存しない（D-05-04）。

---

## 6-6. media

| 分類 | 列 |
| --- | --- |
| 本文 | `title_raw` / `title_rendered`、`caption_raw` / `caption_rendered`、`description_raw` / `description_rendered`、`alt_text` |
| ファイル | `source_url`、`mime_type`、`media_type`、`width`、`height`、`filesize`、`sizes`（JSON） |
| 基本 | `slug`、`status`、`link` |
| 参照 | `author_id` / `wordpress_author_id` |
| 添付先 | `wordpress_post_id`（受け取ったとおり）、`post_id` / `page_id`（記事を解決できた場合。多くとも一方）（D-10-02） |
| 日時 | `wordpress_date` / `wordpress_date_gmt`、`wordpress_modified` / `wordpress_modified_gmt` |

* 添付先がカスタム投稿タイプの場合は、`wordpress_post_id` だけを保存する。
* メディアファイルそのものは保存しない。

---

## 6-7. statuses / types / taxonomies

WordPressの定義情報。一意キーは `blog_id + slug`。表示名（サイトの言語）と、カスタム投稿タイプ・カスタムタクソノミーの検出に使う（D-05-05）。

| テーブル | 主な列 |
| --- | --- |
| `statuses` | `slug`、`name`、`public` 等の定義 |
| `types` | `slug`、`name`、`description`、`hierarchical`、`rest_base`、`rest_namespace`、`taxonomies`（JSON） |
| `taxonomies` | `slug`、`name`、`description`、`hierarchical`、`rest_base`、`rest_namespace`、`types`（JSON） |

それぞれ履歴テーブル（`status_histories`、`type_histories`、`taxonomy_histories`）を持つ。

`show_in_rest` の列は持たない。REST APIはREST APIで公開されている投稿タイプ・タクソノミーだけを返すため、取得したものは常に公開されている（D-19-02）。

---

## 6-8. custom_contents / custom_terms / custom_content_terms

REST APIで公開されているカスタム投稿タイプ・カスタムタクソノミー（D-10-04）。**同期と閲覧だけ**の対象とし、編集案・反映・評価の対象外とする。

| テーブル | 内容 |
| --- | --- |
| `custom_contents` | カスタム投稿タイプの本体。`type` で種類を区別する。列は `posts` と同じ構成（`format`・`sticky`・`comment_status`・`ping_status` を除く）に、`parent_id` / `wordpress_parent_id`、`menu_order` を加える |
| `custom_terms` | カスタムタクソノミーの項目。`taxonomy` で種類を区別する。列は `categories` と同じ構成 |
| `custom_content_terms` | 中間テーブル（`custom_content_id + custom_term_id` をUNIQUE） |

* 一意キーは `blog_id + wordpress_id`（WordPressの投稿IDはすべての投稿タイプで共通の番号、項目IDはすべてのタクソノミーで共通の番号のため）。
* 対象外：`wp_` で始まる投稿タイプとタクソノミー、`nav_menu_item`、`nav_menu`。`attachment` は `media` で扱う。
* 履歴テーブル：`custom_content_histories`、`custom_term_histories`。関連の付け替えは、項目名 `terms` として記録する。
* 本文からの抽出（7章）と、競合の判定は行わない（編集案・反映の対象外のため）。カスタムタクソノミーの項目の削除では、関連していた内容を取得し直さない（更新日時が変わったときに取り込む）。

---

# 7. 本文から抽出するもの

同期で投稿・固定ページの本文（`content_raw`）が新規・変更になったときに、BlogOSが抽出して保存する。WordPress APIへのアクセスは増えない（D-08-04、D-10-02）。

## 7-1. internal_links

実際の内部リンク（同じブログ内へのリンク）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `post_id` / `page_id` | リンク元の記事（どちらか一方） |
| `target_url` | リンク先のURL（本文のとおり） |
| `target_post_id` / `target_page_id` | リンク先の記事（解決できた場合。多くとも一方） |
| `anchor_text` | アンカーテキスト |

リンク元の本文が変わるたびに、その記事の行を作り直す。

対象は、ブログのホームURLと同じサイト（ホスト名とパス）の絶対URLと、`/` で始まるパスとする。ページ内リンク（`#...`）、`mailto:` などのURL以外のもの、`wp-admin`・`wp-content`・`wp-includes`・`wp-json`・`feed` のパス、`../` などの相対パスは対象外とする。リンク先は `normalized_path` で照合する（D-20-07）。

同期で記事が新しく作成された場合、または記事の `link` が変わった場合は、リンク先が未解決（`target_post_id` / `target_page_id` がともにNULL）の行を再照合する（D-15-09）。`article_media` の未解決の行も、メディアが新しく作成された場合に同様に再照合する。

## 7-2. article_media

本文中で使われているメディア（旧 `post_media`）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `post_id` / `page_id` | 記事（どちらか一方） |
| `source_url` | 本文中の画像等のURL |
| `wordpress_media_id` / `media_id` | 対応するメディア（本文から判別できた場合） |

同じサイトの画像（`<img>`）だけを対象とする（アフィリエイトの計測用の画像など、外部の画像は除く）。メディアは、画像のクラス `wp-image-<ID>` があればそのIDで、なければURL（縮小版のURLは元のファイルのURLに戻して）で照合する。メディアライブラリを使わずにアップロードした画像（例：si-note の `/wp-content/img/`）は、メディアに対応しない（D-20-07）。

---

# 8. 履歴

## 8-1. 基本方針

現在の状態は通常のテーブルに、過去の変更は履歴テーブルに保存する。

履歴テーブルは、履歴を持つテーブルごとに `<テーブル名の単数形>_histories` として設ける（4章の一覧）。

## 8-2. 履歴テーブルの構造

変更した項目ごとに1行とし、変更前後の値は全文を保存する（D-05-09）。

| 列 | 内容 |
| --- | --- |
| `id` | ― |
| `blog_id` | ― |
| `<対象>_id` | 対象レコード（例：`post_id`） |
| `change_set_id` | 同時に起きた変更をまとめるID（UUID） |
| `field` | 変更した項目名（列名。関連は `categories` / `tags` 等） |
| `old_value` / `new_value` | 変更前後の値（全文。LONGTEXT） |
| `source` | 変更元（8-3） |
| `sync_run_id` | 同期による変更の場合 |
| `wordpress_push_operation_id` | 反映・回復処理による変更の場合 |
| `user_id` | 利用者の操作による変更の場合（手動編集・反映の承認） |
| `changed_at` | 変更日時 |

* 新規作成（初回取得を含む）は、項目ごとの行を作らず、`field = __created` の1行だけを記録する（作成時の値は現在の値と履歴から復元できるため）。
* 完全削除の検知は `field = __deleted`、復活（削除を検知したデータが再び取得された場合）は `field = __restored` の1行を記録する。
* 差分がない場合は履歴を作らない（反映後の同期で二重に記録されることを防ぐ）。

## 8-3. 変更元（source）の列挙値

（D-02-07）

| 値 | 意味 | 記録されるテーブル |
| --- | --- | --- |
| `wp_initial_sync` | ブログ登録時の初回取得（新しく作られたレコード） | WordPress由来 |
| `wp_sync` | 同期で取り込んだWordPress側の変更 | WordPress由来 |
| `blogos_push` | BlogOSから反映し、WordPressが返した結果 | WordPress由来 |
| `blogos_recovery` | 反映記録からの回復処理 | WordPress由来 |
| `blogos_manual` | BlogOS画面での手動編集 | `blog_histories`、`article_draft_histories`、`article_management_histories` |
| `ai` | BlogOSのAI機能による生成 | `article_draft_histories`、`article_management_histories` |
| `system` | 移行処理・バッチなどの内部処理 | すべて |

* 「変更元」「実行契機（`sync_runs.trigger`）」「実行者（`user_id`）」は別の概念として扱う。
* 「なぜ変わったか」は、`sync_run_id`・`wordpress_push_operation_id` 等のひも付けで表す。
* AIはWordPress由来のテーブルを直接変更しないため、WordPress由来のテーブルの履歴に `ai` は現れない。
* WordPress側で誰が変更したかは、WordPressの標準APIでは取得できない。

---

# 9. BlogOS独自のテーブル（記事）

## 9-1. article_drafts

BlogOS上の編集案（D-01-06〜D-01-08、D-08-06）。

| 列 | 内容 |
| --- | --- |
| `uuid` | 外部に渡す識別子（WordPressの投稿メタ `_blogos_draft_id` に書き込む）。UNIQUE |
| `blog_id` | ― |
| `post_id` / `page_id` | 対象の記事（既存記事の改修の場合。多くとも一方） |
| `target_type` | 作成する記事の種類（`post` / `page`。新規記事の場合） |
| `base_wordpress_modified_gmt` | 編集の起点にしたWordPressの版（競合チェックに使う） |
| 内容 | `title_raw`、`content_raw`、`excerpt_raw`、`slug`、`status`（反映時に設定するステータス）、`wordpress_category_ids` / `wordpress_tag_ids`（反映時に設定するWordPress IDの一覧。JSON。投稿のみ）、`wordpress_featured_media_id` |
| `state` | 状態（`editing` 作業中 / `review` 確認待ち / `pushed` 反映済み / `discarded` 破棄） |
| `origin` | 作成元（`human` / `ai`） |
| `ai_generation_id` | 元になったAI実行記録（AIが作成した場合） |
| `human_edited` | AIの出力を人が修正したか |
| `edit_ratio` | 修正の量（AIの出力と反映内容との差の割合） |
| `revision_scope` | 改修範囲（軽微な改善 / 構成の見直し / 全面改修。D-06-01） |
| `created_by` | 作成した利用者（`users.id`） |
| `pushed_at` / `discarded_at` | ― |

* 反映に成功したら、WordPressの返却値から `posts` / `pages` を作成・更新し、新規の場合は `post_id` / `page_id` を設定する。
* 破棄は `state = discarded` とするだけで、行は削除しない（D-09-08）。
* 同じ記事に、`editing` / `review` の編集案がある場合、同期で差分を検出したら競合として扱う（D-01-04）。競合の解消で「WordPressの変更を取り込む」を選んだ場合は、`base_wordpress_modified_gmt` を最新の値に更新する（WORDPRESS_API 21-2）。

### article_draft_histories

編集案の変更履歴（D-15-04）。構造は8章。内容（`title_raw`、`content_raw` 等）の変更と、状態（`state`）の変更を記録する。AIが作成した編集案の作成は、変更元 `ai` の `__created` として記録する。

---

## 9-2. article_managements

記事ごとのBlogOS独自の管理情報。記事1件につき1行（D-08-02）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `post_id` / `page_id` | 記事（どちらか一方。それぞれUNIQUE） |
| `article_type` | 記事種類（ブログ別の定義に従う） |
| `article_subtype` | 細分類 |
| `main_search_intent` | 主の検索意図 |
| `sub_search_intents` | 副の検索意図（JSON） |
| `work_status` | 作業状態（下表） |
| `memo` | メモ |

記事種類などの値が妥当かどうかは、そのブログの品質基準（ブログ別の定義）で検証する。

`work_status` の値（D-15-10）：

| 値 | 意味 |
| --- | --- |
| `not_started` | 未着手 |
| `needs_revision` | 改修対象 |
| `in_progress` | 改修中 |
| `in_review` | 確認待ち |
| `done` | 完了 |

### article_management_histories

記事の管理情報の変更履歴（D-15-04）。構造は8章。`article_managements` の列に加え、キーワード（`article_keywords`）と記事同士の関係（`article_relations`）の変更も、項目名 `keywords` / `relations` として記録する。

---

## 9-3. article_keywords

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `post_id` / `page_id` | 記事（どちらか一方） |
| `keyword` | キーワード |
| `keyword_type` | `main` / `sub` |

一意キー：記事 + `keyword`。

---

## 9-4. article_relations

人が登録する、設計上の記事同士の関係（D-08-04）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `post_id` / `page_id` | 関係元の記事（どちらか一方） |
| `related_post_id` / `related_page_id` | 関係先の記事（どちらか一方） |
| `relation_type` | 関係の種類（下表） |
| `sort_order` | 並び順 |

`relation_type` の値（D-15-10）。各ブログでの意味（例：si-noteでの「上位」は親ロードマップ）は、品質基準のブログ別の定義で定める。

| 値 | 意味 |
| --- | --- |
| `parent` | 上位の記事（関係元が所属するハブ記事） |
| `child` | 下位の記事（関係元がハブ記事として束ねる記事） |
| `previous` | 前の記事（学習順） |
| `next` | 次の記事（学習順） |
| `related` | 関連記事（理解に必要な記事を含む） |
| `advanced` | 発展・応用の記事 |
| `comparison` | 比較記事 |
| `troubleshooting` | エラー・問題解決の記事 |
| `monetization` | 収益記事 |

`internal_links`（7-1）と比べて、リンク漏れや孤立記事を検出する。

---

## 9-5. article_evaluations / article_evaluation_details

記事の品質評価（D-06-02、D-06-09、D-07-03）。1つの記事を複数回評価できる。

### article_evaluations

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `post_id` / `page_id` | 記事（多くとも一方） |
| `article_draft_id` | 編集案を評価した場合 |
| `evaluated_wordpress_modified_gmt` | 公開中の記事を評価した場合の、評価時点の版 |
| `evaluator_type` | 評価した主体（`ai` / `human`） |
| `ai_generation_id` | AIが評価した場合のAI実行記録 |
| `quality_common_version` | 共通基準のバージョン |
| `quality_profile` / `quality_profile_version` | ブログ別の定義とそのバージョン |
| `required_conditions_passed` | 必須条件をすべて満たしたか |
| `score` | 点数（対象項目で100点に換算した値。小数第1位まで。D-15-01） |
| `is_confirmed` | 人が確定した評価か |
| `confirmed_by` / `confirmed_at` | 確定した利用者と日時 |

* 記事または編集案のどちらか少なくとも一方を持つ。
* 公開の可否は、人が確定した評価（`is_confirmed`）で判断する。

### article_evaluation_details

| 列 | 内容 |
| --- | --- |
| `article_evaluation_id` | ― |
| `item_key` | 評価項目（品質基準で定義したキー。D-06-08） |
| `judgment` | ○ / △ / × / 要人間確認 |
| `points` | 得点（△は配点の50%。切り上げない。D-06-03） |
| `comment` | 理由・改善点 |

---

## 9-6. ai_generations

BlogOSのAI機能の実行記録（D-07-04、D-07-07）。

| 分類 | 列 |
| --- | --- |
| 対象 | `blog_id`、`post_id` / `page_id`（多くとも一方）、`article_draft_id` |
| 実行の内容 | `purpose`（実行モード：品質診断、構成案、改修、新規作成、SEO分析、HTML出力など）、`revision_scope` |
| 生成方法 | `execution_method`（`manual` / `api`）、`provider`、`model`（API実行は応答に含まれる正確なモデル名。手動実行は利用プランと画面に表示されたモデル名）、`reasoning_effort` |
| バージョン | `template_key` / `template_version`、`quality_common_version`、`quality_profile` / `quality_profile_version` |
| 入出力 | `input`、`output`（全文。LONGTEXT） |
| 費用 | `input_tokens`、`output_tokens`、`estimated_cost` |
| 状態 | `status`、`error`、`requested_by`（`users.id`）、`started_at`、`completed_at` |

* 新規記事の作成では、記事を持たない（`post_id` / `page_id` ともにNULL）。
* 認証情報と、保存しないと決めた個人情報（D-05-04）はAIに送らないため、入力にも含まれない。
* 記事の生成方法は「記事 → `wordpress_push_operations` → `article_drafts` → `ai_generations`」の順にたどる。

---

# 10. 記録のテーブル（同期・反映）

## 10-1. sync_runs

1回の同期ごとに1行（D-04-01）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `trigger` | 実行契機（`initial` / `scheduled` / `manual` / `recovery`）。回復処理は通常、毎日の同期（`scheduled`）の最初に行う。`recovery` は、回復処理だけを単独で手動実行した場合に使う（D-15-07） |
| `status` | `running` / `succeeded` / `partial`（一部のリソースが失敗） / `failed` |
| `started_at` / `finished_at` | ― |
| `triggered_by` | 手動で実行した利用者（`users.id`） |
| `message` | 補足（異常終了で「実行中」のまま残った記録を片付けた場合など） |

## 10-2. sync_run_resources

同期1回 × リソース種別ごとに1行。

| 列 | 内容 |
| --- | --- |
| `sync_run_id` | ― |
| `resource_type` | `settings`・`statuses`・`types`・`taxonomies`・`authors`・`categories`・`tags`・`media`・`pages`・`posts` |
| `fetched_count`、`created_count`、`updated_count`、`unchanged_count`、`deleted_count`、`error_count` | 件数 |
| `status` | ― |
| `started_at` / `finished_at` | ― |
| `message` | 失敗した場合の内容 |

## 10-3. sync_issues

人の対応が必要な問題。ログイン時・ダッシュボードの通知は、未解決のものを表示する（D-01-05）。

| 列 | 内容 |
| --- | --- |
| `blog_id` | ― |
| `sync_run_id` | 同期で検出した場合 |
| `wordpress_push_operation_id` | 反映に関する問題の場合 |
| `issue_type` | `fetch_error`（取得エラー）、`unresolved_reference`（参照先が未解決）、`conflict`（競合）、`deleted_detected`（削除を検知）、`mass_deletion_suspected`（大量の消失）、`home_changed`（サイトアドレスの変更。D-19-04）、`push_unknown`（反映結果が不明） |
| `resource_type` / `resource_key` | 対象。`resource_key` はWordPress IDまたはslug。参照先の未解決では「WordPress ID:参照の種類」（例：`101:投稿者`）とする（D-19-05） |
| `post_id` / `page_id` / `article_draft_id` | 関連する記事・編集案（該当する場合） |
| `message` | 内容 |
| `error_status` / `error_body` | エラー時のHTTPステータスと応答本文（全文。D-10-03） |
| `first_detected_at` / `last_detected_at` | 最初に検出した日時と、最後に検出した日時（D-19-05） |
| `resolved_at` / `resolved_by` / `resolution` | 解決の日時・利用者・内容 |

同じ対象（`resource_type` + `resource_key`、または関連する記事・編集案・反映記録）・同じ `issue_type` の未解決の問題が既にある場合は、新しい行を作らず、既存の行の `last_detected_at` と内容を更新する（毎日の同期で同じ問題が増え続けることを防ぐ。D-15-08）。

## 10-4. wordpress_push_operations

BlogOSからWordPressへの反映操作ごとの記録（D-01-09、D-04-02）。

| 列 | 内容 |
| --- | --- |
| `uuid` | UNIQUE |
| `blog_id` | ― |
| `article_draft_id` | 記事の反映の場合 |
| 対象 | `post_id`、`page_id`、`category_id`、`tag_id`、`media_id`（多くとも1つ。新規作成ではすべてNULL） |
| `resource_type` | 投稿、固定ページ、カテゴリ、タグ、メディア |
| `operation` | `create` / `update` / `status_change` / `trash` / `delete` |
| `state` | `pending` / `sent` / `wp_succeeded` / `completed` / `failed` / `unknown` |
| `request_summary` | 送信内容の要約（JSON。認証情報は含めない）。カテゴリ・タグ・メディアの反映では、承認時の入力内容そのもの（D-15-05） |
| `base_values` | カテゴリ・タグ・メディアの反映で、利用者が画面を開いた時点の値（JSON）。反映直前の競合確認に使う（D-15-05） |
| `wordpress_id` / `response_modified_gmt` | WordPressの応答（受信直後に最優先で保存） |
| `error_status` / `error_body` | エラー時のHTTPステータスと応答本文（全文） |
| `message` | 結果の説明（中止した理由など） |
| `approved_by` | 反映を承認した利用者（`users.id`） |
| 日時 | `sent_at`、`wp_succeeded_at`、`completed_at`、`failed_at` |

* `sent` / `unknown` / `wp_succeeded` の記録がある編集案は、再反映できない。

---

# 11. 主キー・外部キー・UNIQUE・INDEX

## 11-1. 外部キー

ブログに属するデータは、`blogs` への外部キーを持つ。記事・編集案などへの参照も外部キー制約を付ける（3-5）。

## 11-2. 削除時の動作

（D-09-07）

| 関係 | 動作 |
| --- | --- |
| `blogs` に属するテーブル | CASCADE（削除が起きるのはブログの完全削除のときだけ） |
| WordPress由来のデータ同士（投稿とカテゴリなど） | RESTRICT（同期では物理削除しないため、通常は起きない） |
| NULLを許す参照（`author_id`、`featured_media_id`、`parent_id` など） | SET NULL（WordPress IDの列は受け取ったとおりの値を残す） |
| CHECK制約を付ける記事の参照（`post_id` / `page_id` など）と、反映記録の対象 | CASCADE。MySQLでは、CHECK制約の列に SET NULL を使えないため。投稿・固定ページは同期で物理削除しないため、働くのはブログの完全削除のときだけである（D-20-01） |

## 11-3. UNIQUE

| テーブル | 一意キー |
| --- | --- |
| WordPress由来（数値IDを持つもの） | `blog_id + wordpress_id` |
| statuses・types・taxonomies | `blog_id + slug` |
| blogs | `home` |
| blog_credentials | `blog_id` |
| blog_settings | `blog_id + key` |
| 中間テーブル | 両側のID（例：`post_id + category_id`） |
| article_managements | `post_id`、`page_id`（それぞれ） |
| article_drafts・wordpress_push_operations | `uuid` |

## 11-4. INDEX

検索・JOIN・同期で頻繁に使う列に付ける。候補：

* `blog_id`、`wordpress_id`
* `slug`、`status`
* `wordpress_modified_gmt`、`wordpress_date_gmt`
* `normalized_path`
* 履歴の対象ID・`change_set_id`・`changed_at`
* `sync_runs` の `blog_id + started_at`
* `sync_issues` の `blog_id + resolved_at`

INDEXは無条件に追加せず、実際の検索条件・データ量を考慮して決定する。

## 11-5. CHECK制約

* 記事の参照（3-5）の「どちらか一方」「多くとも一方」
* `wordpress_push_operations` の対象（多くとも1つ）

CHECK制約は、利用するDBのバージョンが対応していることを実装前に確認する（MySQL 8.0.16以上、MariaDB 10.2以上で有効）。

---

# 12. Google関連データ

## 12-1. 認証と対応先

| テーブル | 内容 |
| --- | --- |
| `google_accounts` | Googleアカウントごとの OAuth トークン（暗号化）、有効期限、スコープ（D-03-05） |
| `blog_google_properties` | ブログごとの対応先（GA4のプロパティ、Search Consoleのサイト、AdSenseのアカウント）と、使用する `google_account_id` |

* 認証は、GA4・Search Console・AdSense とも OAuth（利用者のGoogleアカウントでのログイン）に統一する。サービスアカウントは使わない（D-21-01）。
* `google_accounts`：`email`（Googleアカウントのメールアドレス）、`access_token` / `refresh_token`（`encrypted` キャスト。TEXT型）、`token_expires_at`、`scopes`（JSON）、`connected_by`（`users.id`）、`last_refreshed_at`、`last_error`。トークンは画面に表示しない。
* `blog_google_properties`：`blog_id`、`service`（`ga4` / `search_console` / `adsense`）、`google_account_id`、`resource_name`（GA4は `properties/<ID>`、Search Consoleはサイト（`sc-domain:example.com` 等）、AdSenseは `accounts/pub-...`）、`display_name`、`adsense_domain`（AdSenseで集計するドメイン）。一意キーは `blog_id + service`。

## 12-2. 指標のテーブル

次の原則に従う（D-08-05）。

* Googleから受け取ったURL（ページのパス等）はそのまま保存する。
* 対応する記事を、`posts` / `pages` の `normalized_path` と照合し、解決できれば `post_id` / `page_id` を設定する（多くとも一方）。
* slugの変更でURLが変わった記事も照合できるよう、履歴に残っている過去の `link` でも照合する。
* 取得期間（集計の対象期間）を必ず記録する。

粒度と保存期間（D-21-02〜D-21-05）：

| テーブル | 粒度 | 主な列 |
| --- | --- | --- |
| `google_analytics_site_daily` | ブログ×日 | `active_users`、`new_users`、`sessions`、`engaged_sessions`、`screen_page_views`、`user_engagement_duration`（秒） |
| `google_analytics_page_daily` | ページ×日 | `page_path`、`screen_page_views`、`active_users`、`sessions`、`engaged_sessions`、`user_engagement_duration` |
| `google_analytics_page_channel_daily` | ページ×流入元×日 | 上に加えて `channel_group`（GA4の既定のチャネルグループ） |
| `google_search_console_site_daily` | ブログ×日 | `clicks`、`impressions`、`ctr`、`position` |
| `google_search_console_page_daily` | ページ×日 | `page_url`、`clicks`、`impressions`、`ctr`、`position` |
| `google_search_console_query_daily` | ページ×検索クエリ×日 | 上に加えて `query` |
| `google_adsense_site_daily` | ブログ×日 | `currency_code`、`estimated_earnings`、`page_views`、`impressions`、`clicks` |
| `google_adsense_page_daily` | ページ×日 | `page_url`、上と同じ指標（AdSense APIがページ単位の集計を返す場合だけ使う） |

* ページ単位の表は、`normalized_path`、`post_id` / `page_id`（多くとも一方）を持つ。URL・パス・クエリは長さの制限がないため、一意キーには、値のハッシュ（`*_hash`、SHA-1）を使う。
* 全ての表は `blog_id`、`date`（集計の対象日。GA4・Search Consoleはプロパティのタイムゾーン、AdSenseはアカウントのタイムゾーン）、`fetched_at` を持ち、一意キーは `blog_id + date + （ページ・流入元・クエリ）` とする。
* 平均や率（`ctr`、`position` 等）はGoogleが返した値を保存し、期間の集計では表示回数などで重み付けする。ユーザー数は日をまたいで足し合わせられないため、期間のユーザー数は表示しない（日ごとの値だけを示す）。
* Googleの数値は数日のあいだ更新されるため、取得のたびに直近の数日を取得し直し、その日の行を置き換える。
* 保存期間は無期限とする（Search Consoleは16か月より前をGoogleから取り直せないため）。量はDB確認画面で確認し、必要になったら見直す。
* 取得の実行記録は `google_fetch_runs`（`blog_id`、`service`、`trigger`、`status`、`date_from` / `date_to`、`row_count`、`message`、`error_status` / `error_body`、`started_at` / `finished_at`）に残す。取得の失敗は `sync_issues`（`fetch_error`、`resource_type` は `google_ga4` 等）に記録して通知する。

---

# 13. 削除の扱い

## 13-1. WordPress側での削除

| 事象 | BlogOSでの扱い |
| --- | --- |
| ゴミ箱に移動 | ステータスの変更（`status = trash`）として履歴に記録する（D-09-01） |
| 完全削除（ゴミ箱を含むIDの一覧から消えた） | `wordpress_deleted_at` を記録し、履歴（`__deleted`）と `sync_issues` に記録して通知する。評価・AI実行記録・管理情報は残す。通常の一覧には表示しない（D-09-02） |

* Laravel標準の `deleted_at`（SoftDeletes）は、「BlogOS側で削除した」と混同するため使わない。
* WordPressは削除したIDを再利用しない。

## 13-2. 削除判定の安全策

* IDの一覧をエラーなく最後まで取得できた場合だけ、削除を判定する（D-09-03）。
* 一度に一定の割合（初期値10%。設定値）を超えるデータが消えた場合は、削除として扱わず `sync_issues` に登録して人の確認を待つ。ただし、消えた件数が一定の件数（初期値2件。設定値）に満たない場合は、割合に関係なく削除として扱う（件数の少ないリソースで、1件の削除が止められないようにするため。D-19-01）。
* 一覧から消えたものが再び一覧に現れた場合は、`wordpress_deleted_at` を解除し、履歴に `__restored` を記録する。
* カテゴリ・タグ・メディアの削除を検知したら、DB上でそれに関連していた投稿を再取得する（関連の付け替えでは投稿の `modified_gmt` が変わらないため。D-09-04）。

## 13-3. BlogOSから削除する場合

`BLOGOS_WORDPRESS_API.md` で定義する（投稿・固定ページは既定でゴミ箱へ移動。D-09-05）。反映に成功した結果は 13-1 と同じ扱いでDBに記録する。

## 13-4. ブログの削除

| 操作 | 内容 |
| --- | --- |
| アーカイブ（既定） | `blogs.archived_at` を記録する。同期・反映・AIの対象から外す。元に戻せる |
| 完全削除 | 確認のためにブログのURLを入力させ、登録時と同じ正規化をした上で `blogs.home` と照合する。一致したら、そのブログに属するデータ（履歴・認証情報を含む）を削除する（CASCADE） |

いずれもWordPress側には何もしない（D-09-06）。

## 13-5. BlogOS独自データの削除

* 編集案は `state = discarded` とし、行は残す（D-09-08）。
* 記録類は、14章の保存期間を過ぎたら定期処理で物理削除する。

---

# 14. 保存期間

| データ | 保存期間 |
| --- | --- |
| `sync_runs` / `sync_run_resources` | 1年（D-04-08） |
| `sync_issues` | 解決から1年。未解決のものは削除しない |
| `wordpress_push_operations` | 完了から1年。未完了（`completed` 以外）のものは削除しない |
| `ai_generations` | 採用されたもの（反映された編集案にひも付くもの）は無期限。それ以外は1年（D-07-04） |
| `login_histories` | 1年（D-17-03） |
| 履歴テーブル | 未決定（16章） |
| 評価結果 | 未決定（16章） |

保存期間は、テーブルの状態（15章）を見ながら短縮する可能性がある。期間を過ぎたものは、Schedulerに登録した定期処理で削除する。

---

# 15. テーブルの状態確認

DB確認画面で、各テーブルの件数・データ量・履歴の増え方を確認できるようにする（D-05-10）。値はMySQLの `information_schema`（`TABLES` の行数・データサイズ等）から取得する。

---

# 16. 未決定事項

## 16-1. 各テーブルの最終的な列

データ型・NULL可否・Default値の最終確定と、WordPress APIの各フィールドを保存するかどうかの細部は、実装時に本書の規則に従って決定し、本書へ反映する。

## 16-2. Google関連のテーブル

指標のテーブル・粒度・保存期間は、Google連携の設計時に決定する。

## 16-3. 履歴と評価結果の保存期間

履歴と評価結果をどの期間保持するかは未決定とする。

## 16-4. ログのDB保存

同期の記録（10章）以外のAPIログ・エラーログをDBへ保存するかどうかは未決定とする。

## 16-5. 設定値の保存先

AIの実行方式（手動／API）の切り替え、費用の上限、受け入れの目安の点数、削除判定の割合などの設定値を、configに置くかDBに置くかは実装時に決定する。

## 16-6. 大量データへの対応

アクセスデータや履歴などが大量になった場合の、集計テーブル・アーカイブ・パーティションなどの方式は、必要になった段階で決定する。

---

# 17. 他設計資料との関係

各設計資料の役割分担と優先順位は `CLAUDE.md` で定義する。設計上の決定の経緯は `BLOGOS_DECISIONS.md` に記録する。

# 18. 本書と現在の実装との関係

本書は、現在存在するMigrationやModelの説明書ではない。現在の実装との差異の扱いは `CLAUDE.md` に従い、現在の実装状況は `BLOGOS_CURRENT_STATUS.md` に記録する。

---

# 19. DB設計の完了条件

本書は、以下が明確になっている状態を完成状態とする。

* テーブルの分類（WordPress由来・BlogOS独自・記録）が明確である
* ID・列の命名規則が明確である
* 記事の参照方法が明確である
* 各テーブルの役割と主要な列が明確である
* 履歴の構造と変更元の列挙値が明確である
* 同期・反映の記録が明確である
* 外部キー・削除時の動作・UNIQUE・INDEX・CHECKの方針が明確である
* 削除の扱いが明確である
* 保存期間が明確である
* Google関連データの原則が明確である
* 未決定事項が明確に分離されている
* 現在の実装に引きずられない理想DB設計になっている

以上を、BlogOS DB設計における基本方針とする。
