# BlogOS 現在の実装状況

**調査日:** 2026-09-26
**調査対象:** コミット `e1c5142`（作業ツリーに未コミットの変更なし）
**基準とした設計:** 設計書 v2.0.0（docs/ 7ファイル、CLAUDE.md）、品質基準 1.0.0（resources/quality/）、決定記録 D-01〜D-15

## 1. 本書について

本書は、現在の実装の状態を記録する。理想の設計を定義するものではなく、設計書を書き換える根拠として使ってはならない（`CLAUDE.md` 6-3）。

### 1-1. 調査の方法

* ソースコード（app/、database/、routes/、resources/views/、config/、tests/、bootstrap/）を読んで確認した。
* DBは、読み取りだけのコマンド（`php artisan migrate:status`、`php artisan db:show --counts`）で確認した。
* **画面の表示やコマンドの実行による動作確認は行っていない。** 「バグ」は、コードの静的な確認で実行時にエラーになると判断したものである。

### 1-2. 分類

`CLAUDE.md` 6-2 の分類を使う：一致／不足（未実装）／相違／バグ／過剰（不要・重複）／設計上の懸念／要確認

---

## 2. 最初に対応が必要な問題

セキュリティ → データ整合性 → 重大なバグ、の順に並べる。

| # | 分類 | 内容 | 場所 |
| --- | --- | --- | --- |
| P0 | **セキュリティ** | `users` に、Laravelの初期状態のSeederで作られた「Test User」（`test@example.com`、パスワードはFactoryの既定値）が存在する。**誰でも推測できる認証情報でログインできる**。本番環境で同じSeederを実行すると、同じアカウントが作られる | [DatabaseSeeder.php](../database/seeders/DatabaseSeeder.php)、[UserFactory.php:31](../database/factories/UserFactory.php#L31) |
| P1 | **セキュリティ** | 管理者のログインパスワードが、平文のままSeederに書かれ、**Gitの履歴に含まれている**（コミット `5f812bf` 以降）。**リポジトリは公開（Public）であるため、このパスワードは漏えいしたものとして扱う** | [AdminUserSeeder.php:17](../database/seeders/AdminUserSeeder.php#L17) |
| P2 | **バグ（重大）** | `BlogRepository` に存在しないメソッド `getSelectedOrFirst()`・`selectBlog()` を呼んでいる。この処理は、ログイン後のすべての画面に適用されるMiddlewareの中にあるため、**ログイン後のすべての画面がエラーになる**と判断される | [ShareCurrentBlog.php:24](../app/Http/Middleware/ShareCurrentBlog.php#L24)、[DashboardController.php:26-29](../app/Http/Controllers/DashboardController.php#L26-L29)、[SettingsController.php:16](../app/Http/Controllers/SettingsController.php#L16) |
| P3 | **バグ（重大）** | ブログ以外のWordPress APIのServiceが、`new WordPressApiClient($blogId, $blogRepository)` で呼び出している。しかし、`WordPressApiClient` は `Blog` を1つ受け取る形で定義されている。そのため、**ブログ詳細以外のAPI確認画面はすべてエラーになる** | [CategoryService.php:15-18](../app/Services/WordPress/CategoryService.php#L15-L18) ほか8つのService |
| P4 | **バグ** | `BlogRepository::diff()` が、DTOに存在しないキー（`gmtOffset`、`timezoneString`）を参照している。**毎日のブログ情報の更新処理**と、**既に登録済みのブログの再登録**がエラーになる | [BlogRepository.php:18-19](../app/Repositories/BlogRepository.php#L18-L19) |
| P5 | **バグ** | 画面の中で、定義されていないルート名が11か所で使われている。該当する画面を表示するとエラーになる（ブログ一覧・ブログ詳細も含む） | 3-5 参照 |
| P6 | **バグ** | URLが `/api/` で始まるAPI確認画面（HTMLの画面）で、エラーがJSONで返される設定になっている | [bootstrap/app.php:18-19](../bootstrap/app.php#L18-L19) |

**P0・P1 について**：`CLAUDE.md` 13章に従い、実装に進む前に報告した（2026-09-26）。

必要な対応：
1. 管理者のログインパスワードを変更する（同じパスワードを他のサービスで使っている場合は、そちらも変更する）。
2. Seederから平文のパスワードを除く（例：環境変数から読む）。
3. Test User を削除し、`DatabaseSeeder` から Test User の作成を除く。
4. Gitの履歴からの削除は任意とする。公開済みのため、履歴を書き換えても、既に複製・キャッシュされた内容は消せない。パスワードの変更を主な対策とする。

Gitの履歴を確認した結果、`.env`・Googleの鍵ファイル・トークンのファイルは、コミットされたことがない。

---

## 3. 領域ごとの状況

### 3-1. ログイン・認証

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| ログイン必須 | 一致 | ログイン画面以外のルートに `auth` を適用している（`/up` のヘルスチェックを除く） |
| 利用者の登録 | 一致 | Seederで1人を登録し、新規登録画面はない（D-03-01） |
| パスワードの扱い | セキュリティ | P1 |
| ログインの試行回数の制限 | 一致 | 5回失敗で1分間停止。止めた試行も記録（2026-09-26 対応。D-17-06） |
| `users` テーブルの件数 | セキュリティ | 2件。1件は管理者、もう1件は `DatabaseSeeder` が作成した Test User（P0） |

### 3-2. ブログ管理・選択中ブログ

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| `blogs.home` の一意制約 | 一致 | UNIQUE（D-13-01） |
| `blogs.is_selected` | 一致 | あり（D-02-05） |
| `blogs` の列 | 相違 | `name`・`description`・`url`・`gmt_offset`・`timezone`（WordPressの設定）を `blogs` に持っている。設計では `blog_settings` に分ける（D-10-01） |
| `blogs.last_synced_at` | 相違 | 設計では持たない（D-04-04） |
| `display_name`・`quality_profile`・`archived_at` | 不足 | ない |
| 選択中ブログの取得 | バグ | P2 |
| 選択の自動設定 | 設計上の懸念 | `findBySelected()` は、選択中のブログがないと最初のブログを自動で選択し、DBを書き換える。画面を表示するだけでDBが更新される |
| ブログ切り替え | 不足 | `blog_id` の検証がない（存在しないIDは例外になる）。更新画面でのブログIDの照合がない（D-02-05） |
| ブログ登録の確認 | 不足 | 入力URLの `/wp-json` を1回取得するだけ。HTMLからのAPI Discovery、認証の確認、`home` の正規化、WordPress側の拡張の判定がない（WORDPRESS_API 29章） |
| ブログ登録の保存 | 設計上の懸念 | 保存時に、ブラウザから送られた値（サイト名・`home` 等）をそのまま保存している。APIから取得し直していない |
| 認証情報の登録 | 不足 | ブログごとの認証情報の入力・保存がない（D-03-02） |
| アーカイブ・完全削除 | 不足 | ない（D-09-06） |

### 3-3. WordPress API Client・認証情報

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 接続先 | 一致 | `blogs.home` を基準にしている（D-13-01） |
| timeout | 一致 | 10秒を設定している |
| 認証情報 | 相違 | `config/services.php` の `wp`（.env の `WP_APP_USER`・`WP_APP_PASSWORD`）を、**すべてのブログに共通で**使っている。設計はブログごとに暗号化してDBへ保存（D-03-02） |
| Clientの呼び出し | バグ | P3 |
| エラーの扱い | 相違 | 通信エラー・HTTPエラーを `null` にして返し、エラーの内容（ステータス・応答本文）が失われる。ログも出していない（DEVELOPMENT_RULES 12章、D-10-03） |
| まとめての作成・更新・削除 | 設計上の懸念 | 途中で失敗すると `[]` を返し、既に成功した分が分からなくなる（`createCategories` 等） |
| WordPressへの書き込み | 相違 | 各Serviceに作成・更新・削除のメソッドがあり、反映記録・競合確認を通さずに直接送信する作りになっている（現在、画面からは呼ばれていない）（D-01-09） |
| リトライ | 不足 | ない（WORDPRESS_API 27章） |
| 置き場所 | 相違 | `app/Services/WordPress/`。設計は `app/Clients/WordPress/`（D-11-03） |
| サイト内検索 | 相違・過剰 | `SiteSearchController` が `https://si-note.com` を直接書き込み、Controllerから直接APIを呼んでいる。Search APIは将来の候補（D-10-05） |

### 3-4. DTO

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 応答の保持 | 一致 | APIの応答を配列のまま保持している（WORDPRESS_API 3-2） |
| 名前・置き場所 | 一致 | `app/DTO/WordPress/*ApiDto` |
| 必須フィールドの確認 | 一致 | `REQUIRED_FIELDS` で確認している |

### 3-5. 画面・ルート

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 定義されていないルート名 | バグ | P5。`blog-detail`・`blog-list`（ブログ一覧・詳細）、`blog-info`・`blog-info.show`（投稿のAPI確認画面）、`page-info`・`page-info.show`（固定ページのAPI確認画面）、`analytics-info`・`analytics-catalog`、`site-search`、`adsense-info`（AdSenseの認証後）、`register`（welcome画面） |
| API確認画面のエラー | バグ | P6 |
| ルート名の規則 | 相違 | `database-blog-list` のようなハイフン区切り。設計は `database.blogs.index` のようなドット区切り（D-11-04） |
| Controllerの作り | 相違 | 画面ごと（`XxxListController`、`XxxDetailController`）。設計はリソースごと（D-11-04） |
| API確認画面の名前空間 | 相違 | `Controllers\Api`。設計は `Controllers\WordPressApi`（`Api` はBlogOS自身のJSON用）（D-11-02） |
| DB確認画面の名前空間 | 一致 | `Controllers\Database` |
| Viewのディレクトリ | 相違 | `api/`。設計は `wordpress-api/`（D-11-02） |
| URLのブログID | 相違 | カテゴリ・投稿者の画面で `{blogId}` をURLに含めている。設計は選択中ブログを使う（D-02-05） |
| HTMLの直接出力 | 設計上の懸念 | 固定ページのAPI確認画面で、WordPressの本文を `{!! !!}` で出力している（[page-list.blade.php:63,78](../resources/views/api/page-list.blade.php#L63)）（DEVELOPMENT_RULES 13-3） |
| CSRF | 一致 | POSTのフォームには `@csrf` があり、fetchでもトークンを送っている |
| 空のファイル | 過剰 | 0バイトのControllerが28個ある（`Controllers/Database` の Post・Page・Media・Status・Type・Taxonomy・Tag・Author の List／Detail／Register／HistoryDetail など） |
| ルートに接続されていないController | 過剰 | 中身のあるControllerのうち、`Api` の Author・Media・Status・Tag・Taxonomy・Type の Detail など16個が、どのルートからも使われていない |
| 存在しないクラスの `use` | 過剰 | `routes/web.php` で、存在しないController（例：`Database\CategoryDetailController`、`Database\TagRegisterController`）を読み込んでいる（ルートでは使っていないため、エラーにはならない） |
| テーマ切り替え | 一致 | `config/blogos.php` の `theme`（blank／ironman）で画面の見た目を切り替える機能。残す方針が決まり、設計書に追加した（D-16-01） |
| レスポンシブ対応 | 要確認 | 未確認 |

### 3-6. DB・Migration・Model

**DBの状態**（ローカル、MySQL 8.0.46）：BlogOSのテーブルは `blogs`・`blog_histories`・`categories`・`category_histories` だけで、どれも0件。

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| テーブル | 不足 | 設計のテーブルの大部分がない（`blog_credentials`、`blog_settings`、`posts`、`pages`、`tags`、`authors`、`media`、statuses・types・taxonomies、カスタム投稿タイプ、中間テーブル、`internal_links`、`article_*`、`ai_generations`、`sync_*`、`wordpress_push_operations`、`google_*` と、それらの履歴） |
| テーブルのないModel | 過剰・相違 | `Post`・`Page`・`Tag`・`Author`・`Media`・`Status`・`Type`・`Taxonomy`、中間テーブルとそれらの履歴のModelがあるが、Migrationもテーブルもない |
| Modelの列 | 相違 | 例：`Post` は `post_id`・`status_id`・`type_id`・`title`・`content`（1列）。設計は `wordpress_id`、`status`（文字列）、`title_raw`／`title_rendered` 等（D-02-02、D-05-06、D-05-07） |
| WordPress IDの列名 | 相違 | `categories.category_id` にWordPressのカテゴリIDを入れている。一方、`category_histories.category_id` は内部IDを指しており、**同じ列名で意味が違う**。設計は `wordpress_id`（D-02-02） |
| 親カテゴリ | 相違 | `categories.parent` にWordPress IDだけを持つ。設計は `parent_id`（内部ID）と `wordpress_parent_id`（D-02-03） |
| Categoryの `fillable` | バグ | Modelの `fillable` は `parent_id` で、Repositoryは `parent` で保存している。**親カテゴリが保存されない**。テーブルにない列（`taxonomy`、`last_synced_at`）も含まれている（[Category.php:16](../app/Models/Category.php#L16)、[CategoryRepository.php:67](../app/Repositories/CategoryRepository.php#L67)） |
| 共通の列 | 不足 | `synced_at`・`wordpress_modified_gmt`・`wordpress_deleted_at` がない（DATABASE 3-6） |
| 外部キーの削除時の動作 | 相違 | `blog_histories`・`category_histories` はブログへの外部キーに CASCADE がない。設計はブログに属するテーブルを CASCADE（D-09-07） |
| 履歴の値の型 | 相違 | `old_value`／`new_value` がTEXT。設計はLONGTEXT（全文を保存するため） |

### 3-7. Repository

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| DBアクセスの経由 | 一致（1か所を除く） | Controllerからは、Repositoryを経由している。ただし、`UpdateBlogsFromApi` は `Blog::all()` を直接使っている（D-11-01） |
| `BlogRepository` | バグ | P2、P4 |
| `CategoryRepository::diff()` | バグ | DTOのプロパティ（`$data->name` 等）を参照しているが、DTOは配列 `$data->data` しか持たない。呼び出し元がまだないため、現在は表に出ていない |
| カテゴリの削除 | バグ | 物理削除する。履歴がある場合は、外部キーのためにエラーになる。設計は論理削除（D-09-02） |
| 遅延読み込みの検出 | 不足 | `Model::preventLazyLoading()` の設定がない（D-11-01） |

### 3-8. 同期

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 毎日の実行 | 一部一致 | `blogs:update-from-api` を毎日03:00に実行するよう登録済み。ただし、対象はブログの基本情報だけで、P4のためエラーになる |
| 他のリソースの同期 | 不足 | 投稿・固定ページ・カテゴリ等の更新コマンド9個は、**0バイトの空ファイル** |
| 同期の実行記録・問題の記録 | 不足 | `sync_runs`・`sync_run_resources`・`sync_issues` がない（D-04-01） |
| 2段階の差分判定・削除の検知 | 不足 | ない（D-04-05、D-09-02） |
| ロック・Queue・「今すぐ同期」 | 不足 | ない（D-04-07） |
| 回復処理・反映記録 | 不足 | ない（D-01-09） |

### 3-9. 履歴

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 項目ごとに1行 | 一致 | `field`・`old_value`・`new_value` の構造 |
| 変更元の値 | 相違 | `'定期自動更新'`・`'手動更新'` などの日本語の自由な文字列。設計はEnumの値（`wp_sync` 等）（D-02-07） |
| 新規作成の履歴 | 相違 | 新規作成時に、全項目ぶんの履歴を作っている。設計は `__created` の1行（D-13-04） |
| `change_set_id`・`sync_run_id`・`user_id` 等 | 不足 | ない（DATABASE 8-2） |

### 3-10. 反映・編集案・記事管理

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 編集案・反映記録・競合確認 | 不足 | ない（D-01-06〜D-01-09） |
| WordPress側の拡張（`_blogos_draft_id`） | 不足 | テーマ側・BlogOS側とも未実装（D-01-12） |
| 記事の管理情報・キーワード・関係・内部リンク | 不足 | ない（D-08-02〜D-08-04） |

### 3-11. Google連携

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| 実装の状態 | 相違 | GA4・Search Console・AdSense のAPI確認画面がある。ただし、ControllerからGoogleのAPIを直接呼んでいる箇所がある（Search Console）（ARCHITECTURE 19章） |
| 認証情報 | 相違 | サービスアカウントの鍵とAdSenseのトークンを、ファイル（`storage/app/google/`）で保存している。設計はDBに暗号化して保存（D-03-05）。これらのファイルはGitに含まれていない（一致） |
| 対象の指定 | 相違 | GA4のプロパティIDなどを .env で1つだけ持つ。ブログごとの対応先がない（D-03-05） |
| 記事との対応付け | 不足 | ない（D-08-05） |
| 開発の段階 | 過剰 | 現在の実装は試作とし、分析機能の段階（REQUIREMENTS 11-3）で設計に沿って作り直す。現在のコードはGitの履歴に残っている（D-16-02） |

### 3-12. BlogOSのAI機能・品質評価

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| AI機能・評価 | 不足 | 未実装（開発フェーズの4番目。REQUIREMENTS 11-4） |
| `blogs.quality_profile` | 不足 | ない |

### 3-13. 設定・テスト・その他

| 項目 | 分類 | 状況 |
| --- | --- | --- |
| `.env.example` のDB | 相違 | `DB_CONNECTION=sqlite`。実際の設定と設計はMySQL（D-12-05） |
| `phpunit.xml` のDB | 相違 | SQLiteのメモリDB。設計はMySQLのテスト専用DB（DEVELOPMENT_RULES 6-3、16-2） |
| `.env.example` の `APP_DEBUG` | 要確認 | `true`。本番環境（XServer）の `.env` では `false` にする必要がある |
| テスト | 不足 | Laravelの初期状態のサンプル（ExampleTest）だけ |
| ログ | 不足 | `Log::` の使用がない。エラーの記録が残らない |
| `Enums/`・`Jobs/`・`Clients/`・`Ai/` | 不足 | ディレクトリがない（D-11-03） |
| コメント | 一致 | 日本語で書かれている（D-11-06） |

---

## 4. まとめ

| 分類 | 主な内容 |
| --- | --- |
| 一致 | ログイン必須、`blogs.home` の一意制約、`is_selected`、DTOの作り、CSRF、コメントの言語、DB確認画面の名前空間 |
| 不足 | 設計のテーブルの大部分、同期の仕組み全体、反映・編集案、記事の管理情報、認証情報の保存、AI・評価、テスト、ログ |
| 相違 | WordPress認証情報を全ブログ共通で .env に持つ、WordPress IDの列名、`blogs` の列の構成、変更元の値、ルート名・Controllerの作り、Google認証情報のファイル保存 |
| バグ | P2〜P6（ログイン後の全画面、API確認画面、ブログ情報の更新、ルート名、エラーの形式）、Categoryの保存・差分・削除 |
| 過剰 | 0バイトのファイル37個（Controller 28・Command 9）、ルートに接続されていないController、テーブルのないModel、存在しないクラスの `use` |
| 設計上の懸念 | ログインの試行回数の制限なし、ブログ登録でブラウザから送られた値を保存、画面の表示でDBを書き換える、本文のHTML出力、途中失敗時に `[]` を返す |
| 要確認 | `APP_DEBUG` |
| セキュリティ | P0（推測できる認証情報の Test User）、P1（公開リポジトリの履歴に管理者の平文パスワード） |

現在の実装は、設計のうち「ログイン」「ブログ登録（一部）」「ブログとカテゴリのテーブル」「WordPress・GoogleのAPI確認画面（試作）」にあたる。コアとなる同期・反映・記事管理はまだない。既存のコードの多くは、設計の命名・構造と異なるため、改修よりも設計に沿った作り直しが適する部分が多い。改修の方針と優先順位は、手順5で決める。

---

## 5. 更新履歴

| 日付 | 内容 |
| --- | --- |
| 2026-09-26 | 決定は D-27：まとめて実行の記事改修の後に編集案を品質診断し、改修前後の点数を記録（結果の画面・編集案の画面）。改修範囲の「点数で自動判別」（70点以上 軽微、40点以上 構成の見直し、それ未満 全面改修）を、手動のまとめて実行とAIの設定（自動の再評価の後の改修）で選べるようにし、初期値にした。AIによる記事の管理情報（記事種類・キーワード・検索意図）の案と、人が確認して登録する画面（まとめて作成・1記事ずつの作成）。`article_management_suggestions` を追加。テスト147件が成功 |
| 2026-09-26 | 診断の後の編集案の作成を実装（決定は D-26）：品質診断のまとめて実行（手動・自動の再評価）が終わったら、基準に満たない記事の記事改修を続けてまとめて実行する。作業中の編集案がある記事は改修しない。`ai_batches.follow_up`・`parent_batch_id`、`blog_ai_settings.auto_revision_*` を追加。テスト145件が成功 |
| 2026-09-26 | BlogOSのAI機能のまとめて実行と、条件による自動の再評価を実装（決定は D-25）：標準のモデルを gpt-6-luna・medium に変更。`ai_batches`・`ai_batch_items`・`blog_ai_settings` と `article_evaluations.inbound_link_count` を追加。まとめて実行の画面（品質診断：全て・未評価・再評価の条件・点数の基準、記事改修：点数の基準）、進み具合と記事ごとの結果、取り消し、費用の上限での停止。AIの設定の画面（自動の再評価の有効・無効、モデル・推論の深さ、今日の対象）、再評価の条件の判定（未評価・記事の更新・品質基準とテンプレートの更新・リンクの数の変化・アクセスの減少・定期的な見直し）、`ai:auto-reevaluate`（毎日 日本時間6:00）。テスト142件が成功。実データでの実際のまとめて実行は未実施 |
| 2026-09-26 | BlogOSのAI機能のAPI実行を実装（決定は D-24）：OpenAI Responses API の呼び出し（`app/Clients/OpenAi`）、`RunAiApiJob`、実行ごとの手動／API・モデル・推論の深さの切り替え、費用の目安と月の上限、失敗の記録と実行し直し、接続の確認（`php artisan ai:check`）。`ai_generations` にトークン数の内訳と応答のIDを追加。Queueの `retry_after` を1900秒にした。あわせて、ローカルの `artisan serve` で長い回答を貼り付けられない問題を直した（D-22-11）。手動実行の品質診断を実データで確認（利用者がChatGPTで実行）。テスト137件が成功。実際のAPIでの確認は、利用者の OpenAI API の契約後に行う |
| 2026-09-26 | メタディスクリプションに対応（決定は D-23）：AIOSEO の項目から取得して `posts`・`pages` に保存し、編集案で編集・反映できるようにした（si-note で書き込めることを下書きのテスト投稿で確認し、テスト投稿は削除）。BlogOSのAI機能の指示文・出力・品質診断に反映（テンプレート 1.1.0・1.0.1）。`blogs:sync --full` を追加し、si-note の全件を取得し直した（設定あり：投稿184・固定ページ11、未設定で自動の説明：投稿10・固定ページ2）。その際に見つかった差分の判定の不具合2つ（`*_rendered` の変化を履歴に記録していた、JSON列のキーの順序の違いを変更と判定していた）を直し、直した後の全件の取得し直しで変更0件を確認。テスト131件が成功 |
| 2026-09-26 | 段階6を実施（決定は D-22）：品質基準のファイルの読み込み（9分類・49項目・合計100点、必須条件5つ、記事種類ごとの対象外、バージョン）と点数の計算、`article_evaluations`・`article_evaluation_details`・`ai_generations` を追加。人による評価と確定、AIの評価を初期値にした人の評価、BlogOSのAI機能の手動実行（SEO分析・構成作成・品質診断・記事改修・新規記事作成。指示文の作成 → 回答の取り込み → 評価・編集案として保存、生成方法の記録）、AIの出力をもとにした編集案の人の修正の記録、AI実行テンプレート（`resources/ai/templates/`）を実装。API実行は切り替えの仕組みだけ作り、OpenAI API の契約後に実装する。共通基準を 1.0.1 にした（「AI実行テンプレート（未作成）」の記載の削除）。テスト129件が成功。実データ（si-note）で、5つの実行モードの指示文（約26,000〜55,000文字、置き換え漏れなし、Search Console の検索クエリを含む）と画面の表示を確認。実際にChatGPTで実行しての確認は未実施 |
| 2026-09-26 | 段階5の実データでの確認：利用者がGoogleアカウントを接続し、対応先（GA4のプロパティ、Search Console の `https://si-note.com/`、AdSense のアカウントとドメイン si-note.com）を設定。取得は3サービスとも成功（初回：GA4 9,947行、Search Console 1,686行、AdSense 311行、約2分）。記事への対応付けは、GA4のページ×日 4,771行のうち4,693行、Search Console のページ×日 1,083行のうち1,046行、クエリ×日 414行のうち412行。残りはカテゴリ・投稿者のページ、仮のリンク `xxx.html`、準備中のページ。カテゴリのスラッグ変更前の過去のURLを照合する規則（D-21-08）を追加し、内部リンクも509件のうち460件を照合できるようになった。AdSense の記事ごとの収益は、APIから得られなかった（D-21-09）。テスト120件が成功 |
| 2026-09-26 | 段階5を実施（決定は D-21）：Google連携を作り直した。OAuthに統一し、トークンを `google_accounts` に暗号化して保存、ブログごとの対応先（`blog_google_properties`）、取得の実行記録（`google_fetch_runs`）、指標の表（GA4：ブログ×日・ページ×日・ページ×流入元×日、Search Console：ブログ×日・ページ×日・ページ×クエリ×日、AdSense：ブログ×日・ページ×日）を追加。Google APIは LaravelのHTTPクライアントで REST API を呼ぶ形にし（`app/Clients/Google`）、試作（Controllers\Api の GA4・Search Console・AdSense の画面、サービスアカウントの処理、DTO）と、使わなくなったライブラリ（`google/apiclient`・`google/analytics-data`）を削除。取得（直近4日の取り直し、初回は16か月前から、31日ごとの置き換え、過去の link を含む記事との対応付け、失敗の記録）、毎日の取得（日本時間5:00）、`google:fetch`、Google連携の設定画面（接続・対応先の候補・手動の取得）、分析の画面、記事の詳細のGoogleの指標を実装。あわせて、既存の脆弱性の報告（guzzlehttp/guzzle・league/commonmark）を、両パッケージの更新で解消した。テスト119件が成功。実際のGoogleのデータでの確認は未実施（Googleアカウントの接続が必要）。試作のファイル `storage/app/google/`（サービスアカウントの鍵・AdSenseのトークン）と、.env の `GA4_PROPERTY_ID`・`GSC_SITE_URL` は使わなくなった |
| 2026-09-26 | 段階4の実際の反映を確認（利用者の了承のもと、既存の記事には触れず、公開もしない方法で実施）：si-note に下書きのテスト投稿を新規作成（WordPress ID 3362）→ タイトルを更新（送った項目はタイトルだけ）→ ゴミ箱へ移動 → 完全に削除。4件の反映記録はすべて完了し、履歴はすべて変更元 `blogos_push` で反映記録に結び付いた。その後の同期は成功・変更なし（投稿194件）・未解決の問題0件で、WordPressで投稿3362を取得すると404（削除済み）だった。WordPress側の拡張（投稿メタ）は、テーマ（Theme-SI-Original・Theme-SI-Note）をBlogOSの全作業の後に作り込んでから有効になる |
| 2026-09-26 | 段階4を実施（決定は D-20）：編集案（`article_drafts`・履歴）、反映記録（`wordpress_push_operations`。段階3までの履歴テーブル・`sync_issues` の参照に外部キーを追加）、記事の管理情報・キーワード・記事同士の関係（履歴あり）、内部リンク・本文中のメディア、カスタム投稿タイプ・カスタムタクソノミー（同期と閲覧）、`blog_credentials.connector_extension` を追加。同期に、作業中の編集案がある記事の競合の判定（DBを更新せずに記録）、本文からの抽出と未解決の再照合、同期の最初の回復処理を追加。反映（新規作成・更新・ゴミ箱・完全削除、反映直前の競合確認、結果が不明な場合の確認と拡張による照合、回復処理）、競合の解消（取り込む・破棄・上書き）、カテゴリ・タグ・メディアの更新・削除（値による競合確認、削除後の投稿の取得し直し）と、記事・編集案・反映の確認・反映記録・カテゴリ等の画面を実装。`Theme/Theme-SI-Original` に WordPress側の拡張（`functions.php`・`inc/blogos-connector.php`）を作成。テスト109件が成功。実データ（si-note）では、内部リンク509件のうち458件を記事に照合（残りは仮のリンク `xxx.html` 37件、カテゴリのページへのリンク等）、全画面の表示、21,000文字の記事の編集案の差分表示を確認し、同期は変更なし・問題0件（カスタム投稿タイプはなし）。実際の si-note への反映（書き込み）は未実施 |
| 2026-09-26 | 段階3を実施（決定は D-19）：API確認画面を `Controllers\WordPressApi`・`views/wordpress-api/` で作り直し、旧API確認画面・旧DTO・旧Service・テーブルのないModel・旧カテゴリの画面とコードを削除（実データで 投稿170・固定ページ11・カテゴリ44・タグ12・投稿者1・メディア20・ステータス6・投稿タイプ11・タクソノミー4 件を表示できることを確認。公開済みのみ）。同期の記録（`sync_runs`・`sync_run_resources`・`sync_issues`）、WordPress由来のテーブル（statuses・types・taxonomies・authors・categories・tags・media・pages・posts・中間テーブルと、それぞれの履歴）を追加。同期の仕組み（ブログ単位のロック、2段階の差分判定、削除の検知と安全策、関連していた投稿の取得し直し、参照先の解決、問題の記録）、`blogs:sync`（旧 `blogs:update-from-api` を置き換え）、`SyncBlogJob`、毎日の同期（日本時間3:00）、「今すぐ同期」、同期の状態のJSON（`api.sync.status`）、ダッシュボードの通知、同期の問題の画面、DB確認画面（同期の記録・WordPress由来のテーブル）を実装。テスト73件が成功。ローカルの開発用DBで si-note を実際に同期し、下書き・ゴミ箱を含めて 投稿194・固定ページ14 ほか全件を取り込み、問題0件、参照先がすべて解決できたことを確認。2回目の同期は約5秒で全件「変更なし」、余分な履歴なし。「今すぐ同期」をQueue（database）に登録し、`queue:work --stop-when-empty` で処理できることを確認。XServerでのcronの動作確認は未実施（利用者がMigrationの実行と合わせて行う） |
| 2026-09-26 | 初版。コミット `e1c5142` を調査 |
| 2026-09-26 | 要確認事項の回答を反映。P0（Test User）を追加。リポジトリが公開であることを反映。テーマ切り替えとGoogle連携の扱いを更新 |
| 2026-09-27 | 実データでの確認（ローカルの証明書の問題を D-18-06 で解消した後、テスト専用DBで実施）：ブログ登録は実際の si-note で成功（管理者の認証、サイト設定の取得、WordPress側の拡張は「無効」＝STINGER8のため想定どおり）。旧API確認画面は、ブログ情報・カテゴリ（44件）は正常。投稿・タグ・メディア・投稿タイプ・タクソノミーは0件と表示（実際には存在するため、旧DTO・旧Serviceの取得処理の不具合）。固定ページ・投稿ステータス・投稿者はエラー（旧画面がDTOを配列として扱っている）。旧API確認画面は段階3で作り直すため、個別の修正は行わない |
| 2026-09-27 | 段階2を実施：`blogs` を BlogOS側の情報だけの構造に組み替え（`display_name`・`quality_profile`・`archived_at` を追加、旧列の値と履歴を `blog_settings`・`blog_setting_histories` へ移行）、`blog_histories` を履歴の共通の構造で作り直し、`blog_credentials`（暗号化）を追加。Migrationは環境ごとの列の違いを確認しながら進み、再実行できる形にした（ローカルの `blogs` に `last_synced_at` がなかったため）。API Clientを `app/Clients/WordPress` に移し、ブログごとの認証情報を使う形にした（.env の `WP_APP_USER`・`WP_APP_PASSWORD` は使わない）。ブログ登録（WORDPRESS_API 29章）、認証情報の更新・接続確認、更新系の選択中ブログの照合（`EnsureSelectedBlog`）、サイト設定の同期（`blogs:update-from-api`）を実装。テスト47件が成功。実際の si-note での確認は、ローカルのSSL証明書の問題（Nortonの通信検査）のため未実施 |
| 2026-09-26 | 段階1を実施：P2〜P6を解消。調査時に見つからなかった次の不具合も解消した ―― `Controllers/Database` の11ファイルの名前空間がフォルダと不一致（カテゴリ一覧・カテゴリ変更履歴一覧がクラス未検出）、ブログ詳細（DB確認画面）のルートが存在しないメソッド `index` を呼んでいた、API確認画面のルートにないパラメータ（`blogId`）をControllerが要求していた、メディア一覧・投稿者一覧で画面の変数名とControllerの変数名が不一致、投稿タイプ一覧でDTOを配列として数えていた。API確認画面・カテゴリの画面は、URLのブログIDをやめて選択中のブログを使う形にした。選択中のブログがないときに、表示だけでDBを書き換える動作をやめた。0バイトのファイル37個と存在しないクラスの `use` を削除。`.env.example`・`phpunit.xml` をMySQLに変更し、テスト専用DB（`blogos_testing`）を作成。WordPress API通信の失敗をログに出力。遅延読み込みの検出を有効化。ログイン履歴の確認画面を追加。ブログ切り替えの入力を検証。テスト33件（MySQL）がすべて成功。（訂正 2026-09-27：「実際のWordPress（si-note）の応答で10画面が表示できることを確認」と記録したが、ローカル環境のSSL証明書の問題で通信は失敗しており、旧Serviceがエラーを空のデータとして扱ったため表示されていただけだった。実データでの確認は未実施） |
| 2026-09-26 | 段階0を実施：P0を解消（Seederから削除し、Migrationで既存の Test User を削除）。P1は、Seederからパスワードを除き .env から読む形にした（パスワード自体は利用者の判断で当面変更しない。Gitの履歴には残る）。`login_histories` を追加（`BLOGOS_IMPLEMENTATION_PLAN.md`） |
