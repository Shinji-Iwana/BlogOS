# BlogOS WordPress REST API設計書

**バージョン:** 2.0.0
**最終更新日:** 2026-09-24
**主な変更:** 2026-09-23 設計監査の決定事項（`BLOGOS_DECISIONS.md` の D-01〜D-13）を反映

---

## 1. 本書の目的

本書は、BlogOSとWordPress REST APIとの連携について、どのAPIを利用し、どのような条件で取得し、どのように同期・反映するかを定義する。

本書は、以下の規則を定義する唯一の場所とする（D-12-03）。

* APIの取得条件（8章、第III部）
* 差分の判定方式と同期の手順（第III部）
* WordPressへの反映の手順（第IV部）
* BlogOS連携用のWordPress側の拡張（第V部）

参照元と正本の区分、WordPress APIを直接呼んでよい場面は `BLOGOS_ARCHITECTURE.md`（3-2、3-4）で、DBの列・履歴・削除・保存期間は `BLOGOS_DATABASE.md` で定義する。

WordPress REST APIそのものの解説（第II部）は、実装時の参考として残す。実際の仕様は、WordPress公式のREST API Handbookと、対象サイトのAPI Discoveryの結果を優先する（第VII部）。

本文中の `（D-xx-xx）` は、根拠となる決定事項（`BLOGOS_DECISIONS.md`）を示す。

---

# 第I部 基本方針

# 2. WordPress REST APIの位置付け

WordPress REST APIは、BlogOSとWordPressの境界であり、BlogOSがWordPressのデータを取得・変更する唯一の手段とする。WordPressのDBへ直接アクセスしない。

BlogOSでは、WordPress REST APIを以下の用途で利用する。

1. サイト情報・サイト設定の取得
2. コンテンツ（投稿・固定ページ・カスタム投稿タイプ）の取得
3. カテゴリ・タグ・カスタムタクソノミーの取得
4. ユーザー・メディアの取得
5. ステータス・投稿タイプ・タクソノミーの定義の取得
6. WordPressとBlogOS DBとの差分確認（同期）
7. BlogOSからWordPressへの変更の反映

WordPress APIを直接呼んでよい場面は `BLOGOS_ARCHITECTURE.md` 3-4 に限る。

---

# 3. API連携の基本原則

## 3-1. APIとDBを分離する

WordPress
    ↓
REST API
    ↓
API Client
    ↓
DTO
    ↓
Service
    ↓
Repository
    ↓
BlogOS DB

BlogOSの画面・検索・分析・AIへの入力は、BlogOS DBを参照元とする（ARCHITECTURE 3-2）。

---

## 3-2. DTOはAPIレスポンスをそのまま保持する

* APIレスポンスの意味を変更しない
* APIフィールド名を変更しない（例：`featured_media` を `featuredMediaId` に変えない）
* APIの値をBlogOSの都合で変換しない
* 未知のフィールドが含まれていても、取得を失敗させない

DB保存時には、`BLOGOS_DATABASE.md` に従って必要な情報を正規化して保存する。

---

## 3-3. 取得できる項目と保存する項目を分ける

APIで取得できる項目
        ↓
DTOで保持する項目（原則すべて）
        ↓
BlogOS DBに保存する項目（DB設計書で定義）

APIで取得できるという理由だけで、すべてをDBに保存しない。逆に、ログイン名・メールアドレスなど取得できても保存しないと決めた項目がある（D-05-04）。

---

## 3-4. APIレスポンスの原文

* APIレスポンスの原文はDBに保存しない。DTOがメモリ上で保持する（D-10-03）。
* API確認画面は、表示のたびにAPIを呼び出して結果を表示する。
* 同期・反映でエラーになった場合だけ、応答本文を**全文**、`sync_issues` または `wordpress_push_operations` に保存する。リクエストの `Authorization` ヘッダーは保存しない。

---

# 第II部 WordPress REST APIの基本（参考）

# 4. 基本URL

WordPress REST APIのルートは、通常 `<サイトアドレス>/wp-json/` である。標準のnamespaceは `wp/v2` で、例えばカテゴリAPIは `<サイトアドレス>/wp-json/wp/v2/categories` となる。

REST APIのURLは、WordPressの「サイトアドレス」（`home`）を基準に作られる。「WordPressアドレス」（`url`、WordPress本体の設置場所）ではない（D-13-01）。

---

# 5. API Discovery

## 5-1. 目的

BlogOSは複数のWordPressサイトを管理するため、すべてのサイトが同じAPI構成であるとは限らない。API利用前に以下を確認する。

* REST APIが利用可能か
* `wp/v2` namespaceが存在するか
* 利用可能なnamespace・route・method
* 認証が有効か、対象データへのアクセス権があるか
* サイトアドレス（`home`）

## 5-2. API Rootで取得できる情報

`GET <サイトアドレス>/wp-json/` は、サイトの `name`、`description`、`url`、`home`、`gmt_offset`、`timezone_string`、`namespaces`、`routes`、`authentication` などを返す。

## 5-3. 標準APIと拡張API

プラグインやテーマが独自のnamespace（例：`plugin-name/v1`）を登録することがある。BlogOSでは、標準API（`wp/v2`）と拡張APIを区別して扱う。

---

# 6. エンドポイントの分類

| 分類 | エンドポイント |
| --- | --- |
| サイト情報 | `/wp-json/`、`/wp-json/wp/v2/settings` |
| コンテンツ | `/wp/v2/posts`、`/wp/v2/pages`、カスタム投稿タイプ（`/<rest_namespace>/<rest_base>`） |
| タクソノミー | `/wp/v2/categories`、`/wp/v2/tags`、カスタムタクソノミー（`/<rest_namespace>/<rest_base>`） |
| ユーザー | `/wp/v2/users`、`/wp/v2/users/me` |
| メディア | `/wp/v2/media` |
| 構造の定義 | `/wp/v2/types`、`/wp/v2/statuses`、`/wp/v2/taxonomies` |
| その他（将来の候補） | `/wp/v2/comments`、`/wp/v2/search`、`/wp/v2/posts/{id}/revisions`、Block関連、Themes、Templates、Navigation、Global Styles |

BlogOSが対象とする範囲は 17章（エンドポイント一覧）で定義する。

---

# 7. HTTPメソッド

| メソッド | 用途 | 例 |
| --- | --- | --- |
| GET | 取得 | `GET /wp/v2/posts` |
| POST | 作成・更新 | `POST /wp/v2/posts`、`POST /wp/v2/posts/123` |
| DELETE | 削除（投稿・固定ページは `force` なしでゴミ箱へ移動） | `DELETE /wp/v2/posts/123` |
| OPTIONS | エンドポイントの仕様（スキーマ）の確認 | ― |

---

# 8. 共通パラメータ

| パラメータ | 内容 | BlogOSでの使い方 |
| --- | --- | --- |
| `context` | `view` / `embed` / `edit` | 同期・反映は `edit`（D-04-06）。`raw` の本文や管理情報は `edit` でしか返らない |
| `page` / `per_page` | ページ番号 / 1回の件数（上限100） | `per_page=100` |
| `search` | 検索文字列 | 同期では使わない |
| `include` / `exclude` | ID指定 / ID除外 | 差分の詳細取得で `include[]` を使う |
| `order` / `orderby` | 並び順 | 一覧の取得では `orderby=id&order=asc` を明示し、取得中の変更で並びがずれないようにする |
| `slug` | slugで絞り込み | ― |
| `status` | ステータスで絞り込み | 同期では明示する（13-2） |
| `modified_after` | 更新日時で絞り込み | 反映がタイムアウトした場合の照合で使う（24-3） |
| `_fields` | 返すフィールドを限定 | 差分判定の一覧取得で `_fields=id,modified_gmt` を使う |
| `_embed` | 関連リソースを埋め込む | 同期では使わない。関連はそれぞれのリソースの同期で取得する |

---

# 9. ページネーション

WordPress APIでは、大量データを1回のリクエストで取得しない。`per_page=100` とし、`page=1, 2, 3, ...` と順に取得する。

レスポンスヘッダーの `X-WP-Total`（全件数）と `X-WP-TotalPages`（全ページ数）を使って、取得の終わりを判定する。

---

# 10. 各エンドポイントの主な項目

## 10-1. Settings

`/wp/v2/settings` は管理者権限で取得できる（BlogOSは管理者のApplication Passwordを使う。D-03-04）。

主な項目：`title`、`description`、`url`、`home`、`email`、`timezone`、`date_format`、`time_format`、`start_of_week`、`language`、`use_smilies`、`default_category`、`default_post_format`、`posts_per_page`、`show_on_front`、`page_on_front`、`page_for_posts`、`default_ping_status`、`default_comment_status`、`site_logo`、`site_icon`

BlogOSに保存するキーは `BLOGOS_DATABASE.md` 5-4 で定義する。`timezone_string`・`gmt_offset` はAPI Rootから取得する。

## 10-2. Posts

主な項目：`id`、`date`、`date_gmt`、`guid`、`link`、`modified`、`modified_gmt`、`slug`、`status`、`type`、`password`、`permalink_template`、`generated_slug`、`title`、`content`、`author`、`excerpt`、`featured_media`、`comment_status`、`ping_status`、`format`、`meta`、`sticky`、`template`、`categories`、`tags`

`context=edit` では、`title`・`content`・`excerpt` が `raw`（元の本文）と `rendered`（表示用に整形された本文）の両方で返る。

## 10-3. Pages

Postsとほぼ同じ。`parent`、`menu_order` を持ち、`categories`・`tags`・`sticky`・`format` は持たない。

## 10-4. Categories / Tags

主な項目：`id`、`count`、`description`、`link`、`name`、`slug`、`taxonomy`、`parent`（カテゴリのみ）、`meta`

## 10-5. Users

主な項目：`id`、`username`、`name`、`first_name`、`last_name`、`email`、`url`、`description`、`link`、`locale`、`nickname`、`slug`、`roles`、`capabilities`、`avatar_urls`、`meta`

* 認証なしでは、公開済みの投稿があるユーザー（投稿者）だけが返る。
* 管理者の認証情報と `context=edit` では、全ユーザーと管理用の項目が返る。
* BlogOSは `username`・`email`・`capabilities` を保存しない（D-05-04）。

## 10-6. Media

主な項目：`id`、`date`、`date_gmt`、`guid`、`link`、`modified`、`modified_gmt`、`slug`、`status`、`type`、`title`、`author`、`comment_status`、`ping_status`、`meta`、`description`、`caption`、`alt_text`、`media_type`、`mime_type`、`media_details`、`post`、`source_url`

* `post` は添付先の投稿のIDで、固定ページやカスタム投稿タイプのIDが入ることもある（D-10-02）。
* `media_details` に画像の寸法・ファイルサイズ・サイズ違いの一覧が含まれる。

## 10-7. Statuses / Types / Taxonomies

* Statuses：`publish`、`future`、`draft`、`pending`、`private` などのステータスの定義。表示名はサイトの言語で返る。
* Types：投稿タイプの定義（`name`、`slug`、`description`、`hierarchical`、`rest_base`、`rest_namespace`、`taxonomies` 等）。
* Taxonomies：タクソノミーの定義（`name`、`slug`、`types`、`hierarchical`、`rest_base`、`rest_namespace` 等）。

## 10-8. その他（将来の候補）

Revisions（WordPress側の編集履歴。BlogOSの履歴とは別物）、Comments、Search（WordPress内の検索。BlogOS内の検索とは別物）、Block関連、Themes、Templates、Navigation、Global Styles。現時点では対象外とする（D-10-05）。

---

# 11. 認証と権限

## 11-1. 認証方式

WordPress標準のApplication Passwordsを使い、HTTPSの上でBasic認証として送る（D-03-02、D-03-04、D-03-06）。

* 管理者（BlogOS所有者）のApplication Passwordを、「BlogOS」という名前で専用に発行して使う。漏えいが疑われたら、そのパスワードだけを無効化する。
* 認証情報は `blog_credentials` に暗号化して保存する。画面に再表示しない。
* Application PasswordsはHTTPSを前提とする。

## 11-2. 権限

認証されていることと、すべての操作が可能であることは同義ではない。403が返った場合は、ユーザーの権限と、対象操作に必要な権限（capability）を確認する。

---

# 第III部 同期

# 12. 同期の種類

| 種類 | 実行契機（`sync_runs.trigger`） | 内容 |
| --- | --- | --- |
| 初回取得 | `initial` | ブログ登録時に全データを取得する。履歴の変更元は `wp_initial_sync` |
| 定期同期 | `scheduled` | 毎日1回、cronから実行する（D-01-03） |
| 手動同期 | `manual` | 画面の「今すぐ同期」からJobとして実行する |
| 回復処理 | `recovery` | 止まっている反映を完了させる（24章）。通常は定期同期・手動同期の最初に行い、実行契機はその同期のものとする。`recovery` は、回復処理だけを単独で手動実行した場合に使う（D-15-07） |

同期の全体の流れ（ロック、実行記録、トランザクション）は `BLOGOS_ARCHITECTURE.md` 13-4 で定義する。

---

# 13. 取得条件

## 13-1. 共通

* `context=edit`
* `per_page=100`
* 一覧の取得は `orderby=id&order=asc`
* `_embed` は使わない

## 13-2. ステータス

| 対象 | 指定するステータス |
| --- | --- |
| 投稿・固定ページ | `status=publish,future,draft,pending,private,trash`（ゴミ箱を含めることで、ゴミ箱への移動と完全削除を区別する。D-04-06） |
| カスタム投稿タイプ | 投稿タイプが対応するステータスのうち、上記に該当するもの |
| メディア | `inherit`、`private` |

---

# 14. 取得順序

依存関係に基づき、次の順で取得する（D-05-06）。

1. API Root（`home`、タイムゾーン、namespaceの確認）
2. Settings
3. Types
4. Taxonomies
5. Statuses
6. Users
7. Categories・Tags・カスタムタクソノミー
8. Media
9. Pages
10. Posts・カスタム投稿タイプ

参照先を後から取得する場合も、WordPress IDを受け取ったとおりに保存しておき、参照先を取得した時点で内部IDを設定する（D-02-03）。

---

# 15. 差分の判定

## 15-1. 更新日時を持つリソース（投稿・固定ページ・メディア・カスタム投稿タイプ）

2段階で取得する（D-04-05）。

1. **一覧の取得**：`_fields=id,modified_gmt` で、IDと更新日時だけを全ページ取得する。
2. **比較**：DBの `wordpress_id` と `wordpress_modified_gmt` と比べ、次のように判定する。
   * 一覧にあり、DBにない → 新規
   * 両方にあり、`modified_gmt` が異なる → 変更
   * 両方にあり、`modified_gmt` が同じ → 変更なし
   * DBにあり、一覧にない → 削除の候補（16章）
3. **詳細の取得**：新規・変更のものだけを、`include[]` で最大100件ずつ詳細まで取得する。

## 15-2. 更新日時を持たないリソース（カテゴリ・タグ・ユーザー・カスタムタクソノミー・Settings・定義情報）

毎回全件を取得し、保存している項目を比較する。

## 15-3. 比較する値

* 本文は `raw` で比較する。`rendered` はテーマやプラグインの変更でも変わるため、比較に使わない（D-05-07）。
* 比較の対象は、DBに保存している列だけとする。

## 15-4. 差分を見つけたとき

`BLOGOS_ARCHITECTURE.md` 3-3 の規則に従う（D-01-04）。

* 対象の記事に作業中（`editing` / `review`）の編集案がなければ、DBを更新し、履歴（変更元 `wp_sync`）を記録する。
* 作業中の編集案があれば、DBは更新せず、`sync_issues` に「競合」として記録する。同じ記事の未解決の競合が既にある場合は、新しく作らず既存の記録を更新する（D-15-08）。解消の方法は 21-2 で定義する。

## 15-5. 本文からの抽出

投稿・固定ページの本文が新規・変更になったら、内部リンク（`internal_links`）と本文中のメディア（`article_media`）を抽出し直す（D-08-04、D-10-02）。

記事が新しく作成された場合・記事の `link` が変わった場合・メディアが新しく作成された場合は、参照先が未解決のまま残っている内部リンク・本文中のメディアを再照合する（D-15-09）。

## 15-6. サイトアドレスの変更

API Rootまたは Settings の `home` が `blogs.home` と異なっていた場合は、`blogs.home` を自動で更新せず、`sync_issues` に登録する（D-13-01）。

---

# 16. 削除の検知

## 16-1. 判定

* 15-1 の一覧は、ゴミ箱を含むステータスで取得している。そのため、一覧から消えたものは「完全削除」と判定できる（D-09-01、D-09-02）。
* ゴミ箱への移動は、ステータスが `trash` に変わる「変更」として扱う。
* 更新日時を持たないリソースは、全件取得の結果に含まれないものを削除の候補とする。

## 16-2. 安全策

（D-09-03）

* 一覧を**エラーなく最後まで**取得できた場合だけ、削除を判定する。途中で失敗した場合は、そのリソースの削除判定を行わない。
* 取得結果が0件であることと、取得に失敗したことを区別する。通信の失敗を「データがない」と解釈しない。
* 一度に一定の割合（初期値10%。設定値）を超えるデータが消えた場合は、削除として扱わず、`sync_issues` に「大量の消失」として登録して人の確認を待つ。ただし、消えた件数が一定の件数（初期値2件）に満たない場合は、削除として扱う（D-19-01）。

## 16-3. 削除を検知したとき

* DBでは論理削除（`wordpress_deleted_at`）とし、履歴（`__deleted`）と `sync_issues` に記録する（`BLOGOS_DATABASE.md` 13章）。
* カテゴリ・タグ・メディアの削除を検知したら、DB上でそれに関連していた投稿を詳細まで再取得する。関連の付け替えでは、投稿の `modified_gmt` が変わらないためである（D-09-04）。
* 削除を検知したデータが再び取得された場合は、`wordpress_deleted_at` を解除し、履歴（`__restored`）を記録する。

---

# 17. エンドポイント一覧

BlogOSが対象とするエンドポイント（D-10-05）。

| API | エンドポイント | 用途 | 同期 | 反映 |
| --- | --- | --- | --- | --- |
| API Root | `/wp-json/` | API Discovery、サイトアドレス、タイムゾーン | ○ | ― |
| Settings | `/wp/v2/settings` | サイト設定 | ○ | 将来（段階3の操作） |
| Posts | `/wp/v2/posts` | 投稿 | ○ | ○ |
| Pages | `/wp/v2/pages` | 固定ページ | ○ | ○ |
| Categories | `/wp/v2/categories` | カテゴリ | ○ | ○ |
| Tags | `/wp/v2/tags` | タグ | ○ | ○ |
| Users | `/wp/v2/users` | ユーザー | ○ | ― |
| Media | `/wp/v2/media` | メディア | ○ | ○ |
| Statuses | `/wp/v2/statuses` | ステータスの定義 | ○ | ― |
| Types | `/wp/v2/types` | 投稿タイプの定義 | ○ | ― |
| Taxonomies | `/wp/v2/taxonomies` | タクソノミーの定義 | ○ | ― |
| カスタム投稿タイプ | `/<rest_namespace>/<rest_base>` | カスタム投稿タイプの内容 | ○ | ― |
| カスタムタクソノミー | `/<rest_namespace>/<rest_base>` | カスタムタクソノミーの項目 | ○ | ― |

**将来の候補**：Comments、Search、Revisions、Block関連、Themes、Templates、Navigation、Global Styles、プラグイン独自のAPI

---

# 18. カスタム投稿タイプ・カスタムタクソノミー

（D-10-04）

* Types / Taxonomies の定義から、REST APIで公開されている（`rest_base` を持つ）ものを検出し、すべて同期する。
* 公開されていないものは取得できないため、画面に「REST APIで非公開」と表示する。
* 対象外：`wp_` で始まる投稿タイプとタクソノミー（ブロック、テンプレート、ナビゲーション、グローバルスタイル等）、`nav_menu_item`、`nav_menu`。`attachment` は Media で扱う。
* `rest_namespace` が `wp/v2` 以外の場合も、定義に従ってエンドポイントを組み立てる。
* 同期と閲覧だけの対象とし、反映は行わない。

---

# 19. 同期の単位と再実行

* 同期は、ブログ単位・リソース単位で分けて実行・記録する（`sync_run_resources`）。
* 一部のリソースが失敗した場合、失敗したリソースだけを再実行できるようにする。
* 同じ同期を複数回実行しても、重複データが発生しないようにする（`blog_id + wordpress_id` で既存を確認して作成または更新する）。
* DBが破損した場合や初期化した場合に備え、初回取得と同じ手順でDBを再構築できるようにする。

---

# 第IV部 WordPressへの反映

# 20. 反映の原則

* BlogOSからWordPressへの変更は、必ずWordPress REST APIを使う。WordPressのDBへ直接アクセスしない。
* 反映には必ず人間の承認を必要とする。公開・削除・設定変更は、さらに確認画面を挟む（ARCHITECTURE 18-2 の段階2・3）。
* AIはWordPressへ直接アクセスしない。AIが作成した記事の自動公開はしない（D-07-01）。
* 送信した内容ではなく、WordPressの返却値を正としてDBを更新する（D-01-09）。
* 反映の全体の流れ（反映記録の状態の進み方）は `BLOGOS_ARCHITECTURE.md` 13-5 で定義する。

---

# 21. 競合の確認と解消

## 21-1. 反映直前の競合確認（記事）

既存の記事を更新・削除する前に、次の確認を行う（D-01-08）。

1. `GET /wp/v2/<posts|pages>/{wordpress_id}?context=edit&_fields=id,modified_gmt` で最新の `modified_gmt` を取得する。
2. 編集案の `base_wordpress_modified_gmt` と比べる。
3. 異なれば反映を中止し、`sync_issues` に「競合」として記録して、差分を人に示す。自動マージは行わない。

注意：WordPress REST APIには、条件付き更新（「前回から変わっていなければ更新する」という指定）の仕組みがない。そのため、確認から送信までのごく短い間に変更された場合は検出できない。この残りのリスクは許容し、次の同期で差分として検出する。

## 21-2. 競合の解消

競合（同期で検出したもの、反映直前の確認で検出したもの）は、画面でWordPressの最新の内容と編集案の差分を示し、人が次のいずれかを選ぶ（D-15-03）。

| 対応 | 処理 |
| --- | --- |
| WordPressの変更を取り込む | WordPressの最新の内容でDB（`posts` / `pages`）を更新し、履歴（変更元 `wp_sync`）を記録する。編集案の `base_wordpress_modified_gmt` を最新の値に更新し、編集案は作業中のまま残す。人が差分を見て編集案を直す |
| 編集案を破棄する | 編集案を `discarded` にし、WordPressの最新の内容でDBを更新する（変更元 `wp_sync`） |
| 編集案で上書きする | WordPressの最新の内容でDBを更新し（変更元 `wp_sync`）、編集案の基準の版を最新にしたうえで、通常の反映（22〜23章）を行う。WordPress側の変更は失われるため、確認画面を挟む |

いずれの場合も、対応した `sync_issues` を解決済みにする。

## 21-3. カテゴリ・タグ・メディアの競合確認

カテゴリ・タグ・メディアは編集案を持たない。利用者が承認した入力内容を `request_summary` に、利用者が画面を開いた時点の値を `base_values` に保存する（D-15-05）。

1. 反映の直前に、対象を `context=edit` で取得する。
2. 変更しようとしている項目について、最新の値と `base_values` を比べる（これらのリソースには更新日時がないため、値で比べる）。
3. 異なれば反映を中止し、`sync_issues` に「競合」として記録して、差分を人に示す。

---

# 22. 操作ごとのリクエスト

| 操作 | リクエスト | 補足 |
| --- | --- | --- |
| 新規作成 | `POST /wp/v2/<posts|pages>` | `title`、`content`、`excerpt`、`slug`、`status`、`categories`、`tags`、`featured_media` 等と、`meta._blogos_draft_id`（拡張が有効な場合。第V部）を送る |
| 更新 | `POST /wp/v2/<posts|pages>/{id}` | 変更する項目だけを送る |
| ステータス変更 | `POST /wp/v2/<posts|pages>/{id}` | `status` を送る。公開は段階3の操作 |
| ゴミ箱へ移動 | `DELETE /wp/v2/<posts|pages>/{id}` | `force` を指定しない。応答は `status=trash` の投稿 |
| 完全削除 | `DELETE /wp/v2/<posts|pages>/{id}?force=true` | さらに確認画面を挟んだ場合だけ（D-09-05）。応答は `{deleted: true, previous: {...}}` |
| カテゴリ・タグ・メディアの削除 | `DELETE /wp/v2/<categories|tags|media>/{id}?force=true` | ゴミ箱がないため、常に完全削除になる。必ず確認画面を挟む |
| カテゴリ・タグ・メディア情報の更新 | `POST /wp/v2/<categories|tags|media>/{id}` | ― |

* 投稿・固定ページの削除の既定は「ゴミ箱へ移動」とする。
* いずれの操作も、`wordpress_push_operations` を通して実行する。
* 記事のゴミ箱への移動・完全削除は、作業中の編集案がある場合は行わない（先に編集案を破棄するか反映する）。完全削除は、確認画面で記事のスラッグを入力させる。
* カテゴリ・タグ・メディアの完全削除は、確認画面で名前を入力させる。既定のカテゴリ（`default_category`）は削除できない。
* カテゴリ・タグ・メディアをBlogOSから削除した場合は、関連していた投稿を直ちに取得し直す（WordPressは投稿の関連を付け替えるが、投稿の更新日時は変わらないため。D-09-04）。
* カテゴリ・タグ・メディアで変更できる項目：カテゴリは名前・スラッグ・説明・親、タグは名前・スラッグ・説明、メディアはタイトル・代替テキスト・キャプション・説明。送るのは変更した項目だけとする。

---

# 23. 反映後のDB更新

1. 応答を受信したら、直ちに `wordpress_id` と `modified_gmt` を反映記録に保存する（状態 `wp_succeeded`）。
2. 応答の内容で `posts` / `pages` 等をDBトランザクションの中で更新し、履歴（変更元 `blogos_push`、`wordpress_push_operation_id`、承認した `user_id`）を記録する。
3. 編集案を「反映済み」にする。新規作成の場合は、作成された記事を編集案に結び付ける。
4. 反映記録を `completed` にする。

反映の直後にDBとWordPressは一致しているため、次の同期では差分として検出されず、履歴も重複しない。

---

# 24. 反映の失敗と回復

## 24-1. 失敗の扱い

| 失敗の位置 | 反映記録の状態 | 扱い |
| --- | --- | --- |
| 送信前（競合確認で中止、通信エラー） | `failed` | 編集案はそのまま。やり直せる |
| WordPressが受け付けなかった（HTTP 4xx） | `failed` | 応答本文を保存する。やり直せる |
| 送信中（タイムアウト等で結果が不明）、または HTTP 5xx | `unknown` | `sync_issues` に登録し、人が確認する。5xx はWordPress側で処理が途中まで進んだ可能性があるため、結果が不明として扱う（D-20-06） |
| 受信後のDB更新に失敗 | `wp_succeeded` | 回復処理で完了させる |
| 「送信中」のまま10分を過ぎた（処理が途中で止まった） | `unknown` | 回復処理の最初に変更する |

* 反映は、同期と同じブログ単位のロックの中で行う。同期中は反映できない（D-20-05）。
* 書き込み（POST・DELETE）の待ち時間は60秒とし、取得（10秒）より長くする。長い本文の保存が「結果が不明」にならないようにするため。

`sent` / `unknown` / `wp_succeeded` の反映記録がある編集案は、再反映できないようにロックする。

## 24-2. 回復処理

毎日の同期の最初に、`wp_succeeded` のまま止まっている反映記録について、保存した `wordpress_id` で詳細を取得し、23章の2〜4を実行する（履歴の変更元は `blogos_recovery`）。

## 24-3. 新規作成の結果が不明な場合の照合

1. `modified_after=<送信した時刻から少し前>`、ステータス、`context=edit` で、該当期間に作成・更新された投稿を取得する。
2. WordPress側の拡張（第V部）が有効なブログでは、`meta._blogos_draft_id` が編集案の `uuid` と一致する投稿を探す。見つかれば、その投稿で23章の2〜4を実行する。
3. 拡張が無効なブログ、または見つからない場合は、候補の一覧を画面に表示し、人が照合する（D-01-12）。
4. 作成されていなかったと人が判断した場合は、反映記録を `failed` とし、編集案のロックを解除する。

---

# 第V部 BlogOS連携用のWordPress側の拡張

# 25. 拡張の内容

（D-01-12）

| 項目 | 内容 |
| --- | --- |
| 目的 | 新規作成の結果が不明な場合に、作成された投稿を確実に照合する |
| メタキー | `_blogos_draft_id`（先頭が `_` のため、WordPressの編集画面の「カスタムフィールド」には表示されない） |
| 値 | 編集案の `uuid` |
| 登録方法 | `register_post_meta` で、投稿と固定ページに登録する。`type=string`、`single=true`、`show_in_rest=true`、`auth_callback` で編集権限（`edit_posts`）を持つユーザーだけに読み書きを許可する |
| 置き場所 | `Theme-SI-Original` の `functions.php` から読み込む別ファイル（例：`inc/blogos-connector.php`） |

登録処理の例：

```php
foreach (['post', 'page'] as $type) {
    register_post_meta($type, '_blogos_draft_id', [
        'type'          => 'string',
        'single'        => true,
        'show_in_rest'  => true,
        'auth_callback' => fn() => current_user_can('edit_posts'),
    ]);
}
```

---

# 26. 拡張が有効かどうかの判定

ブログ登録時と接続確認時に、`context=edit` で投稿を1件取得し、応答の `meta` に `_blogos_draft_id` のキーが含まれているかで判定する（値が空でも、登録されていればキーは含まれる）。

* 有効：新規作成時に `meta._blogos_draft_id` を送り、24-3 で照合に使う。
* 無効：`meta` を送らず、24-3 の人による照合で運用する。STINGER8で運用している間はこの状態となる。

---

# 第VI部 エラー・通信・運用

# 27. エラー処理

## 27-1. HTTPステータスごとの扱い

| ステータス | 考えられる原因 | 扱い |
| --- | --- | --- |
| 400 | パラメータの誤り | リトライしない |
| 401 | 認証情報の誤り・無効化 | リトライしない。接続確認を促す |
| 403 | 権限不足 | リトライしない |
| 404 | ID・ルートが存在しない、REST API非公開 | リトライしない。一覧取得で404の場合、削除とは判定しない |
| 405 | メソッドが許可されていない | リトライしない |
| 429 | サーバー・WAF等による制限 | 間隔を空けて限られた回数だけリトライする |
| 500 / 502 / 503 | 一時的なサーバーエラー | 間隔を空けて限られた回数だけリトライする |
| タイムアウト | 通信の遅延 | 取得は限られた回数だけリトライする。反映はリトライせず `unknown` とする（24章） |

## 27-2. 記録する内容

* HTTPステータス、WordPressのエラーコード・メッセージ・`data`
* リクエストURL、HTTPメソッド
* 応答本文（全文。3-4）

認証情報は、画面・ログ・例外メッセージ・記録のいずれにも出力しない。

## 27-3. レスポンスの検証

HTTPステータスの確認 → JSON形式の確認 → 必須フィールドの確認 → DTO生成、の順に処理する。必須フィールドは、各リソースで最低限 `id`（定義情報は `slug`）とし、その他は各DTOで定義する。

---

# 28. 通信の設定

* すべての通信にtimeoutを設定する。
* リトライは無制限にしない。対象は、タイムアウト（取得のみ）、429、5xxに限る。
* 同じブログへの同時大量アクセスを避ける（同期はブログ単位でロックする。ARCHITECTURE 28章）。
* WordPress APIの利用自体に費用はかからないが、通信はWordPressを置いているサーバーの負荷になる。差分だけを取得し、不要な再取得をしない。

---

# 29. ブログ登録時の確認

ブログを登録するときは、次の順に確認する。

1. 入力されたURLの形式が正しいか
2. `<入力URL>/wp-json/` に接続できるか（見つからない場合は、HTMLの `<link rel="https://api.w.org/">` によるDiscoveryも試す）
3. API Rootが取得でき、`wp/v2` が存在するか
4. API Rootの `home` を正規化し、既に登録されたブログと重複していないか（D-13-01）
5. 認証情報で `GET /wp/v2/users/me?context=edit` が成功するか
6. 必要なエンドポイントが存在するか
7. BlogOS連携用の拡張が有効か（26章）

確認後、`blogs` と `blog_credentials` を登録し、初回取得（12章）をJobとして開始する。

---

# 30. URLの扱い

* 利用者が入力するURLには、`https://example.com`、`https://example.com/`、`https://example.com/blog/` などの違いがある。単純な文字列置換に依存せず、API Discoveryの結果（`home`）で確定する。
* サブディレクトリに設置されたWordPress（例：`https://example.com/blog/wp-json/`）にも対応する。
* WordPressの「WordPressアドレス」（`url`）と「サイトアドレス」（`home`）が異なる場合があるため、BlogOSは常に `home` を基準にする。

---

# 31. マルチサイト・バージョン差異・拡張API

* WordPressマルチサイトは、当面は対象外とする（通常の単一サイトを対象とする）。
* WordPressのバージョンや利用している機能によって、利用可能なエンドポイントやフィールドは異なる。API Discoveryと実際のレスポンスで確認する。
* プラグインやテーマが追加したnamespaceは、標準APIと区別する。現時点では同期の対象外とする。

## 31-1. SEOプラグイン（AIOSEO）のメタディスクリプション

WordPressの標準APIにはメタディスクリプションがないため、SEOプラグイン AIOSEO（All in One SEO）が、標準の投稿・固定ページのAPIに加える項目を使う（D-23-01、D-23-02）。AIOSEO独自のnamespace（`aioseo/v1`）は使わない。

| 項目 | 用途 |
| --- | --- |
| `aioseo_meta_data.description` | 記事に設定した説明。取得して `meta_description_raw` に保存する。反映では、`POST /wp/v2/<posts\|pages>(/{id})` に `{"aioseo_meta_data": {"description": "..."}}` を送る（空文字にすると未設定に戻る） |
| `aioseo_head_json.description` | 実際にページに出力される説明（未設定の場合は、AIOSEOが本文から自動で作る）。取得して `meta_description_rendered` に保存する（書き込まない） |

* これらの項目は、APIのスキーマ（OPTIONS）には現れない。si-note（AIOSEO 5.0.2）で、下書きのテスト投稿を使って、作成・更新・空に戻す操作ができること、説明だけの更新でも記事の `modified_gmt` が変わる（毎日の同期で取り込まれる）ことを確認した（2026-09-26）。
* AIOSEOがない・無効なブログでは項目が返らないため、BlogOSは保存済みの値を変えない。反映で送った項目は、WordPressに無視される。
* 保存する項目を増やしたときは、`php artisan blogs:sync --full` で、更新日時に関係なく全ての記事の詳細を取得し直す。

---

# 第VII部 その他

# 32. 開発時の禁止事項

* 各Serviceで独自にAPIのURLを組み立てない（API Clientに集約する）
* API Clientの中でDBに保存しない
* ControllerからWordPress APIへ直接アクセスしない
* DTOでAPIレスポンスの意味を変えない
* 通信の失敗を `[]` などに変換して正常終了させない
* 大量データを1回の処理で無制限に取得しない
* 認証情報をログに出力しない

---

# 33. API追加時の確認事項

新しいAPIを追加する場合は、最低限以下を確認する。

1. エンドポイントとHTTPメソッド
2. 認証と権限
3. レスポンスの構造
4. ページネーション
5. クエリパラメータ
6. エラーレスポンス
7. 関連するリソース
8. DBに保存する項目（`BLOGOS_DATABASE.md` の更新）
9. 履歴の対象かどうか
10. 画面に表示する項目

---

# 34. 未決定事項

| 事項 | 状態 |
| --- | --- |
| 対応するWordPressの最低バージョン | 未決定 |
| カスタム投稿タイプ・カスタムタクソノミーの編集・反映 | 未決定（同期と閲覧は対応済み。D-10-04） |
| Comments・Revisions・Block関連・Themes等への対応時期 | 未決定（将来の候補） |
| Settingsの変更（反映） | 未決定（実装する場合は段階3の操作とする） |
| マルチサイトへの対応 | 未決定 |

以下は決定済みである。

| 事項 | 決定 |
| --- | --- |
| APIレスポンス原文の保存 | 保存しない。エラー時の応答本文だけ全文を保存する（D-10-03） |
| 同期の頻度 | 毎日1回と手動（D-01-03） |
| 差分の判定方式 | IDと更新日時の一覧による2段階の取得（D-04-05） |
| WordPressへの自動反映 | 行わない。必ず人が承認する（D-07-01） |
| 認証方式 | 管理者のApplication Passwordを暗号化して保存（D-03-02、D-03-04） |

---

# 35. 本書の完成条件

* API Discoveryとサイトアドレスの扱いが定義されている
* 対象とするエンドポイントと将来の候補が定義されている
* 認証と権限の方針が定義されている
* 同期の取得条件・取得順序・差分の判定・削除の検知が定義されている
* 反映の手順・競合確認・失敗時の回復が定義されている
* WordPress側の拡張が定義されている
* エラー処理と通信の設定が定義されている
* 未決定事項が明示されている

---

# 36. 他設計資料・現在の実装との関係

各設計資料の役割分担と優先順位、現在の実装との差異の扱いは `CLAUDE.md` で定義する。設計上の決定の経緯は `BLOGOS_DECISIONS.md` に、現在の実装状況は `BLOGOS_CURRENT_STATUS.md` に記録する。

---

# 37. 参考とするWordPress公式仕様

API仕様を確認する際は、WordPress公式のREST API HandbookとREST API Referenceを基準とする。

* REST API Handbook
* REST API Reference（各エンドポイント）
* Discovery
* Global Parameters
* Pagination
* Authentication（Application Passwords）

WordPress REST APIの仕様は、WordPress本体のバージョンやサイト構成によって差異が生じるため、実際の対象サイトでのAPI Discoveryとレスポンスの確認を優先する。
