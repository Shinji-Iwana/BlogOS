# BlogOS 設計決定記録

**バージョン:** 1.0.0
**最終更新日:** 2026-09-26（項目13〜17を追加）

---

## 1. 本書の目的

本書は、BlogOSの設計に関する決定事項を、決定日・内容・理由・影響範囲とともに記録する。

設計書（REQUIREMENTS / ARCHITECTURE / DATABASE / WORDPRESS_API / DEVELOPMENT_RULES / 品質基準）は「現在の正しい設計」を定義する。
本書は「その設計がいつ・なぜそう決まったか」を記録する。

* 設計書と本書が食い違う場合は、設計書の反映漏れとして扱い、本書の決定日以降の内容を確認したうえで設計書を修正する。
* 決定を変更する場合は、既存の記録を書き換えず、新しい決定として追記し、旧決定に「→ D-xx-xx により変更」と記す。
* 現在の実装状況は本書ではなく `BLOGOS_CURRENT_STATUS.md` に記録する。

### 記録の経緯

2026-09-23、設計書6ファイルとCLAUDE.mdを監査し、矛盾・不足・重複・曖昧さを12項目に整理した。
各項目について選択肢を比較し、利用者（BlogOS所有者）が決定した内容を本書に記録する。

### 節番号について

項目1〜12の各決定の「影響」に書いた節番号（例：REQUIREMENTS §12-7）は、**監査時点（2026-09-23、改訂前）の各文書の節番号**である。改訂後の文書では、節番号が変わっている場合がある。項目13以降は、**改訂後（v2.0.0以降）の節番号**である（D-15-12）。

### ID の付け方

`D-<項目番号>-<連番>`（例：D-01-03 は項目1の3番目の決定）

---

## 2. 決定一覧

### 項目1：参照元・正本・下書き・競合

**D-01-01 「情報源」の用語を分割する**
* 決定：「情報源」を次の2つに分けて定義する。
  * **参照元**：画面表示・検索・分析・AI入力が読むデータの場所。常にBlogOS DB。
  * **正本**：データが食い違ったときに正とする側。データ種別ごとに定める（D-01-02）。
* 理由：CLAUDE.md §37「BlogOS DBを情報源とする原則」とWORDPRESS_API §67「WordPressを情報源とする原則」が別の意味で同じ語を使い、正反対に読めたため。
* 影響：CLAUDE.md、ARCHITECTURE、WORDPRESS_API、DEVELOPMENT_RULES

**D-01-02 データ種別ごとの正本**
* 決定：

| データ | 正本 | BlogOS DBの位置付け |
| --- | --- | --- |
| WordPress由来（投稿・固定ページ・カテゴリ・タグ・ユーザー・メディア・設定・カスタム投稿タイプ等） | WordPress | 最後に同期・反映した時点の写し |
| 反映前の編集案・新規記事 | BlogOS | 正本。WordPressへの反映成功後、正本はWordPressへ移る |
| BlogOS独自データ（評価・AI出力・管理情報・履歴・同期記録） | BlogOS | 正本 |
| Google由来の指標 | Google | 取得時点の写し（取得期間を記録） |

* 理由：反映前チェックと反映後のDB更新、毎日の同期によってDBとWordPressの差は最小化できるが、WordPress管理画面での直接編集など、BlogOSを経由しない変更は起こりうるため。
* 影響：ARCHITECTURE、DATABASE

**D-01-03 毎日の同期と手動同期**
* 決定：WordPressとの同期（APIで取得 → DBとの差分チェック）を毎日1回自動実行する。画面に「今すぐ同期」ボタンを設ける。
* 理由：BlogOSを経由しない変更を定期的に検出するため。
* 影響：REQUIREMENTS §8-2・§12-4・§12-5、ARCHITECTURE、WORDPRESS_API

**D-01-04 同期で差分を検出したときの扱い**
* 決定：
  * 対象レコードに作業中の編集案が**ない**場合：WordPressの値でDBを更新し、履歴を記録し、通知する。
  * 編集案が**ある**場合：「競合」として通知し、人間が判断する。DBは自動更新しない。
* 理由：通常の変更は手間なく取り込み、BlogOS側の作業と衝突する場面だけ人が判断できるようにするため。
* 影響：ARCHITECTURE、DATABASE、WORDPRESS_API

**D-01-05 ログイン時・ダッシュボードの通知**
* 決定：ログイン時やダッシュボードでは、直近の同期結果と未解決の差分・競合を**DBから読んで**表示する。ログインのたびにWordPress APIは呼ばない。
* 理由：ブログ数×API数の通信でログインが遅くなることを避け、APIを呼ぶ場面を限定する（D-01-10）ため。
* 影響：REQUIREMENTS、ARCHITECTURE

**D-01-06 反映前の編集案を別テーブルで管理する**
* 決定：BlogOS上の編集案（AIの案・人の修正・新規記事）は、WordPress由来のテーブルとは別の編集案テーブルで管理する。編集案は外部に渡す識別子として `uuid` を持つ。
* 理由：`posts` 等を直接書き換えると、同期で上書きされる、差分検出が成り立たない、未反映の変更が履歴に混ざる、という問題が起きるため。
* 影響：DATABASE（DATABASE §3 の `article_revisions` を含めて整理）

**D-01-07 新規記事の扱い**
* 決定：WordPress未作成の記事は、反映に成功するまで編集案テーブルだけで管理する。反映成功後、WordPressの返却値から `posts`（または `pages`）の行を作る。`posts.wordpress_id` は常に必須とする。
* 影響：DATABASE §6-4

**D-01-08 反映直前の競合チェック**
* 決定：編集案には、編集の起点となったWordPressの `modified_gmt`（基準バージョン）を記録する。WordPressへ反映する直前に最新の `modified_gmt` を取得して比較し、異なれば反映を中止して差分を人に示す。自動マージは行わない。
* 理由：WordPress側の変更を上書きしてデータを失うことを防ぐため。AI処理中に元記事が変更された場合も同じ仕組みで検知できる。
* 影響：WORDPRESS_API、DEVELOPMENT_RULES §315〜§318

**D-01-09 反映後のDB更新と反映記録**
* 決定：
  * WordPressへの反映に成功したら、必ずWordPressの返却値でDBを更新し、履歴を記録する（「必要に応じて」ではない）。
  * 反映操作ごとに反映記録（`wordpress_push_operations`）を先に作成し、状態を `pending` → `sent` → `wp_succeeded` → `completed`（または `failed` / `unknown`）の順に進める。WordPressの応答（`wordpress_id`、`modified_gmt`）は受信直後に最優先で保存する。
  * `sent` / `unknown` / `wp_succeeded` の反映記録がある編集案は、再反映できないようにロックする。
  * `wp_succeeded` のまま止まった反映は、保存したWordPress IDで再取得してDB更新だけをやり直す（毎日の同期の最初に回復処理を行う）。
  * `unknown` は人が確認する。
* 理由：WordPress更新成功後にDB更新が失敗した場合、または通信のタイムアウトで結果が分からない場合に、二重作成や上書きを防ぎ、回復できるようにするため。
* 影響：ARCHITECTURE §20-3、DATABASE、WORDPRESS_API §66、DEVELOPMENT_RULES §245

**D-01-10 WordPress APIを直接呼んでよい場面**
* 決定：次の場面に限定する。①API確認画面 ②ブログ登録・接続確認 ③同期 ④反映と反映直前の競合確認 ⑤反映結果の取得。
* 影響：CLAUDE.md §37、ARCHITECTURE、WORDPRESS_API §44

**D-01-11 BlogOS独自情報を別テーブルに分ける**
* 決定：WordPress APIの取得結果を保持するテーブルには、WordPress由来の列だけを持たせる。BlogOS独自の情報は別テーブルで管理する。同期処理はWordPress由来の列だけを更新する。
* 影響：DATABASE §2-4・§6-2・§33-3

**D-01-12 新規作成がタイムアウトしたときの確認方法**
* 決定：
  * 当面は人が確認する（反映直前の時刻以降に作成された下書きを表示し、人が照合する）。
  * WordPress側では、投稿メタ `_blogos_draft_id` を `register_post_meta`（`show_in_rest`、編集権限のあるユーザーだけに読み書きを許可する `auth_callback`）で登録し、BlogOSが新規作成時に編集案のUUIDを書き込めるようにする。
  * 登録処理は、テーマの基盤である `Theme-SI-Original` の `functions.php` から読み込む別ファイル（例：`inc/blogos-connector.php`）に置く。
  * BlogOSは、ブログの接続確認時にこのメタが有効かどうかを判定する。無効なブログでは人による確認で運用する。
* 理由：Theme-SI-Originalは新しいテーマを作る際の基盤としてコピーされ、Gitで管理されるため、処理の漏れやテーマ切り替えによる機能の消失が起きにくい。
* 注意：STINGER8で運用している間は、この機能は無効。基盤を修正しても、既にコピーした派生テーマには自動では反映されない。
* 影響：WORDPRESS_API（WordPress側の拡張に関する新しい節）、ARCHITECTURE

---

### 項目2：ID命名と変更元

**D-02-01 内部の主キー**
* 決定：すべてのテーブルで自動採番の `id`（bigint）を主キーとする。外部に渡す必要があるものだけ別の列として `uuid` を持つ。
* 影響：DATABASE §25

**D-02-02 WordPress IDの列名**
* 決定：
  * そのテーブル自身のWordPress IDは `wordpress_id`。
  * `id` は常にBlogOSの内部IDとし、WordPress IDを入れてはならない。
  * WordPress由来データの一意制約は `blog_id + wordpress_id` に統一する。
* 理由：DEVELOPMENT_RULES §44（`posts.id` にWordPress IDを持つ場合がある）がID分離の原則と矛盾していたため。
* 影響：DATABASE §6-4・§16-3・§27-1、WORDPRESS_API §42、DEVELOPMENT_RULES §44・§45

**D-02-03 他データへの参照の持ち方と列名**
* 決定：
  * WordPress IDは受け取ったとおりに必ず保存し、内部の外部キーは参照先が存在すれば設定する（存在しなければNULL）。両方を持つ。
  * 参照の列名は、テーブル名ではなく**WordPress APIのフィールド名（役割）**を基準にする。

| APIのフィールド | 内部の外部キー | WordPress ID |
| --- | --- | --- |
| `author` | `author_id` | `wordpress_author_id` |
| `parent` | `parent_id` | `wordpress_parent_id` |
| `featured_media` | `featured_media_id` | `wordpress_featured_media_id` |
| `post`（メディアの添付先） | `post_id` / `page_id` | `wordpress_post_id` |

  * `blog_id` と、多対多の中間テーブルの列（`post_id`、`category_id` 等）は、テーブル名を基準にする。
  * BlogOS内だけで使うテーブルは、内部IDだけで参照する。
  * WordPress IDは、差分チェックとWordPressへの反映に使う。
* 理由：取得順序に依存せず保存でき、参照先が未取得の状態を検出できるため。
* 影響：DATABASE、WORDPRESS_API §63

**D-02-04 数値IDを持たないデータのキー**
* 決定：statuses・types・taxonomies は `blog_id + slug` を一意キーとし、列名はWordPress APIに合わせる。
* 影響：DATABASE §21〜§23・§27

**D-02-05 URLとIDの扱い**
* 決定：
  * 選択中のブログは `blogs.is_selected` で持ち、URLには含めない。
  * ルートパラメータの名前から、内部IDかWordPress IDかが分かるようにする（例：DB確認画面・業務画面は `{post}`、API確認画面は `{wordpressId}`）。
  * 更新系のフォームには、画面表示時の `blog_id` を hidden 項目として含め、送信時に現在の `is_selected` と照合する。一致しなければ処理を止めて警告する。
  * 自動処理（同期・Job・Command）は `is_selected` を使わず、対象ブログを明示的に受け取る。
  * 切り替えは1トランザクションで行う（全ブログの選択を解除 → 対象ブログを選択）。
  * 将来、複数ユーザーに対応する際は、ユーザー単位の選択に移行する。
* 理由：DBのフラグ方式では、複数タブで別のブログを操作してしまう危険、自動処理が選択中のブログしか扱わない危険があるため。
* 影響：DATABASE §4、DEVELOPMENT_RULES §32・§85〜§90・§149

**D-02-06 `blog_id` の意味**
* 決定：`blog_id` は常に `blogs.id`（BlogOS内部のID）を指す。WordPressマルチサイトのサイトIDが必要になった場合は `wordpress_site_id` と呼ぶ。
* 影響：DATABASE、WORDPRESS_API §55

**D-02-07 変更元（source）**
* 決定：
  * 「変更元（source）」「実行契機（trigger）」「実行者（actor）」を別の概念として扱う。DATABASE §40 の「データソース」は、正本の区分（D-01-02）と変更元に統合して廃止する。
  * 変更元の列挙値：

| 値 | 意味 | 記録されるテーブル |
| --- | --- | --- |
| `wp_initial_sync` | ブログ登録時の初回取得（新しく作られたレコード） | WordPress由来 |
| `wp_sync` | 同期で取り込んだWordPress側の変更 | WordPress由来 |
| `blogos_push` | BlogOSから反映し、WordPressが返した結果 | WordPress由来 |
| `blogos_recovery` | 反映記録からの回復処理 | WordPress由来 |
| `blogos_manual` | BlogOS画面での手動編集 | BlogOS独自 |
| `ai` | BlogOSのAI機能による生成・評価 | BlogOS独自 |
| `system` | 移行処理・バッチなどの内部処理 | すべて |

  * AIはWordPress由来のテーブルを直接変更しない（D-01-06、D-07-01）。そのため、WordPress由来のテーブルの履歴に `ai` は現れない。
  * 「なぜ変わったか」は自由記述ではなく、関連する記録へのひも付け（`sync_run_id`、反映記録のID、AI実行記録のID）で表す。
  * 列は文字列型とし、値はPHPのBacked Enumで管理する。
  * WordPressの標準APIでは、WordPress側で誰が変更したかは取得できない。
* 理由：DATABASE §24-3、WORDPRESS_API §80〜§82、DEVELOPMENT_RULES §52 で値が3通りあり、確定していなかったため。
* 影響：DATABASE §24・§40、WORDPRESS_API §80〜§82、DEVELOPMENT_RULES §50〜§52・§336・§337

---

### 項目3：認証・認証情報・動作環境

**D-03-01 BlogOSへのログイン**
* 決定：利用者1人のログインを設ける（Laravel標準の `users`、新規登録画面なし、Seederで1人を登録）。複数ユーザーと権限は未決定のまま残す。
* 理由：BlogOSはWordPressへ公開できる認証情報を持ち、外部のサーバー（XServer）に置かれるため。
* 影響：REQUIREMENTS §12-7、ARCHITECTURE §30-5、DATABASE

**D-03-02 WordPress認証情報の保存**
* 決定：
  * ブログごとのApplication Passwordは、専用テーブル（例：`blog_credentials`）にLaravelの `encrypted` キャストで暗号化して保存する。
  * `APP_KEY` はDBのバックアップとは別に保管する。鍵の差し替えは `APP_PREVIOUS_KEYS` を使う手順で行う。
* 理由：ブログごとに異なる認証情報を .env で持つと、ブログを追加するたびに再デプロイが必要になるため。`blogs` と同じテーブルに置くと、誤って一緒に出力する危険があるため。
* 影響：ARCHITECTURE §25、DATABASE、WORDPRESS_API §31・§110-12、DEVELOPMENT_RULES §109〜§112・§170

**D-03-03 画面での認証情報の扱い**
* 決定：登録した認証情報は再表示せず、「設定済み（最終確認日時）」とだけ表示する。変更は上書きのみとし、「接続確認」ボタンを設ける。API確認画面では `Authorization` ヘッダーを必ず伏せ字にする。
* 影響：WORDPRESS_API、DEVELOPMENT_RULES §169・§302

**D-03-04 WordPress側で使うユーザー**
* 決定：BlogOS専用のWordPressユーザーは作らず、管理者（BlogOS所有者）のApplication Passwordを使う。Application Passwordは「BlogOS」という名前で専用に発行し、漏えいが疑われたらそれだけを無効化する。
* 補足：管理者権限のため、`/settings` と `/users` の `context=edit` を利用できる。保存する項目は、取得できる範囲ではなく必要な範囲で決める（D-05-04）。
* 影響：WORDPRESS_API §31・§32

**D-03-05 Google・AIの認証情報**
* 決定：AIのAPIキーは .env で持つ（BlogOS全体で1つ）。GoogleのOAuthトークンはGoogleアカウント単位でDBに暗号化して保存し、ブログごとの対応先（GA4のプロパティ、Search Consoleのサイト、AdSenseのアカウント）は別に持つ。詳細はGoogle連携の設計時に決める。
* 影響：ARCHITECTURE §19・§25、DATABASE §36

**D-03-06 HTTPSと動作環境**
* 決定：
  * BlogOSとWordPressの両方でHTTPSを必須とする。
  * 動作環境はXServer。ブラウザで利用し、PCとスマートフォンの両方で使えるよう、画面サイズに応じた表示（レスポンシブ）にする。
  * REQUIREMENTSに非機能要件の章を追加する。
* 影響：REQUIREMENTS（非機能要件の章を新設）、ARCHITECTURE

---

### 項目4：同期の記録・取得条件・実行方式

**D-04-01 同期の実行記録**
* 決定：次の3テーブルで記録する。
  * `sync_runs`：1回の同期ごとに1行（`blog_id`、実行契機 `initial` / `scheduled` / `manual` / `recovery`、状態 `running` / `succeeded` / `partial` / `failed`、開始・終了日時）
  * `sync_run_resources`：同期1回×リソース種別ごとの件数（取得・新規・更新・変更なし・削除検知・エラー）と状態
  * `sync_issues`：人が対応すべき問題（取得エラー、参照先が未解決、競合、削除を検知）。対象、内容、解決日時を持つ
* 同期で更新が発生した場合は、各履歴テーブル（`category_histories` 等）にも記録し、`sync_run_id` をひも付ける。
* 影響：DATABASE §30・§39、WORDPRESS_API §68・§69

**D-04-02 反映記録テーブル**
* 決定：D-01-09 の反映記録を `wordpress_push_operations`（仮称）として設ける。同期の実行記録とは別のテーブルとする。
* 影響：DATABASE

**D-04-03 レコード単位の同期状態**
* 決定：`sync_status` のような状態の列は持たない。各テーブルには `synced_at`（最後に照合した日時）と `wordpress_modified_gmt` だけを持ち、問題の有無は `sync_issues` で判断する。
* 理由：同じ情報を二重に持つと食い違いの原因になるため。
* 影響：DATABASE、DEVELOPMENT_RULES §246

**D-04-04 `blogs` の同期状態**
* 決定：`blogs` に同期状態・最終同期日時の列は持たず、直近の `sync_runs` から判断する。
* 影響：DATABASE §4-2

**D-04-05 差分の検出方式**
* 決定：
  * 投稿・固定ページ・メディア・カスタム投稿タイプは、`_fields=id,modified_gmt` でIDと更新日時だけを全件取得し、DBと比較して新規・変更・削除を判定する。そのうえで、新規・変更があったものだけ `include[]` で詳細を取得する。
  * 更新日時を持たないカテゴリ・タグ・ユーザー等は、毎回全件を取得して比較する。
* 理由：`modified_after` だけでは削除を検知できず、時刻のずれによる取りこぼしの危険があるため。
* 影響：WORDPRESS_API §38〜§41・§110-10

**D-04-06 同期時のAPI取得条件**
* 決定：`context=edit`。`status=publish,future,draft,pending,private,trash` を明示する。`per_page=100`。同期では `_embed` を使わない。本文は `raw` と `rendered` の両方を保存する。
* 影響：WORDPRESS_API §8・§12

**D-04-07 XServerでの実行方式**
* 決定：
  * XServerのcronから `php artisan schedule:run` を実行し、毎日の同期を登録する。
  * Queueはdatabaseドライバーを使う。cronから `queue:work --stop-when-empty --max-time=<秒数>` を定期起動する（共用サーバーでは処理プロセスを常駐できないため）。
  * 「今すぐ同期」とAIの実行はJobとして登録するだけにし、画面は状態を定期的に読んで表示する。
  * ブログ単位でロックする（キャッシュロック＋`running` 状態の `sync_runs` の確認。異常終了に備えて一定時間で解除）。
  * トランザクションは1レコード単位（本体・関連・履歴）とする。
* 確認事項：XServerのcronの最短間隔と、PHPの実行時間の上限（CLI・Web）。
* 影響：REQUIREMENTS §12-5、ARCHITECTURE §30-1・§30-3、DEVELOPMENT_RULES §177・§178

**D-04-08 保存期間**
* 決定：`sync_runs` / `sync_run_resources` は1年。`sync_issues` は解決から1年。`wordpress_push_operations` は完了後1年とし、未完了のものは削除しない。期間を過ぎたものは定期処理で削除する。運用状況を見て短縮する可能性がある。
* 影響：DATABASE §37

---

### 項目5：ユーザー・補助情報・本文・履歴

**D-05-01 WordPressユーザーのテーブル**
* 決定：テーブル名は `authors` とする（Laravelの `users` との衝突を避けるため）。管理者の認証情報でUsers APIが返すユーザーを全員保存する。履歴は `author_histories`。
* 影響：REQUIREMENTS §6-6、ARCHITECTURE §13-2・§16-3・§21-1、DATABASE §16・§17

**D-05-02 テーブル名に `wordpress_` を付けない**
* 決定：WordPress由来のテーブル名に `wordpress_` は付けない。

**D-05-03 参照列の命名**
* 決定：D-02-03 のとおり、参照列はAPIのフィールド名（役割）を基準にする。

**D-05-04 WordPressユーザーの保存項目**
* 決定：`wordpress_id`、`name`、`slug`、`url`、`description`、`link`、`avatar_urls`、`roles` を保存する。`username`（ログイン名）、`email`、`capabilities` は保存しない。
* 理由：ログイン名は不正ログインの標的となり、メールアドレスは個人情報で用途がないため。
* 影響：DATABASE §16

**D-05-05 statuses・types・taxonomies**
* 決定：3テーブルとも作り（一意キーは `blog_id + slug`）、毎日の同期で取得する。それぞれ履歴テーブル（`status_histories` 等）を持つ。
* 理由：サイトの言語に合わせた表示名をそのまま使え、カスタム投稿タイプの検出の基盤になるため。表示名が変わった経緯を確認できるようにするため。
* 影響：DATABASE §21〜§23、WORDPRESS_API §21・§62・§89

**D-05-06 投稿からstatuses等への外部キー**
* 決定：外部キーは持たず、`posts.status`・`posts.type` 等にAPIの値をそのまま文字列で保存する。取得順序は依存関係に基づく順とし、実装の優先度は同時とする。
* 影響：DATABASE §21-3、WORDPRESS_API §21-3・§62・§89

**D-05-07 本文の列**
* 決定：`title_raw` / `title_rendered`、`content_raw` / `content_rendered`、`excerpt_raw` / `excerpt_rendered` を持つ（固定ページ・カスタム投稿タイプも同じ）。差分判定と履歴は `raw` で行う。`rendered` は品質評価・AI入力・プレビューに使う。
* 理由：`rendered` はテーマやプラグインの変更でも値が変わるため、比較に使うと実際には変わっていない変更まで検出するため。
* 影響：DATABASE §6・§8

**D-05-08 日時の列**
* 決定：`wordpress_date` / `wordpress_date_gmt` / `wordpress_modified` / `wordpress_modified_gmt` とする。比較と並べ替えにはGMTの値を使う。
* 影響：DATABASE、DEVELOPMENT_RULES §152

**D-05-09 履歴の粒度**
* 決定：変更した項目ごとに1行とし、変更前後の値は全文を保存する。同時に起きた変更は `change_set_id` でまとめる。
* 影響：DATABASE §24

**D-05-10 テーブルの状態確認**
* 決定：各テーブルの件数・データ量・履歴の増え方を画面で確認できるようにする（MariaDB / MySQL の `information_schema` から取得）。
* 影響：REQUIREMENTS §7-6、ARCHITECTURE

---

### 項目6：品質基準

**D-06-01 改修範囲の指定**
* 決定：実行のたびに改修範囲（例：軽微な改善 / 構成の見直し / 全面改修）を指定する。指定がなければ軽微な改善とする。全面改修は人が指定した場合だけ行う。（2026-09-26 に D-27-02 で補足：人が「点数で自動判別」を選んだ場合は、点数から全面改修を選ぶことがある）
* 理由：品質基準書 §5-12（最小限の改修）と §10-3（全面改修を許容）が矛盾していたため。
* 影響：品質基準 §5-12・§10-3

**D-06-02 合格判定**
* 決定：「必須条件」と「点数」の2段階で判定する。必須条件（例：誤情報なし、推測なし、コードの動作確認済み、タイトルと内容の一致、孤立記事でない）を1つでも満たさなければ公開不可。その他のチェック項目は採点の観点とする。

| 点数 | 判定 |
| --- | --- |
| 95〜100点 | 公開可 |
| 90〜94点 | 公開不可（改善して再評価） |
| 89点以下 | 公開不可（大幅な改善が必要） |

* 影響：品質基準 §1-5・§11

**D-06-03 △の点数**
* 決定：△は配点の50%とし、切り上げない（0.5点単位）。合格ラインは95.0点。
* 理由：切り上げでは、1点の項目で△と○が同じ点数になるため。

**D-06-04 テンプレートと分類の集約**
* 決定：記事の構成は第5章を唯一の定義とする。記事種類は4種類（親ロードマップ・子ロードマップ・集客記事・収益記事）、集客記事の細分類は Know / Do / Solve / Compare / 実践。検索意図は Know / Do / Solve / Compare（Mixedは組み合わせとして表す）。

**D-06-05 判断の優先順位**
* 決定：正確性 ＞ 読者の理解（初心者第一）＞ 品質の点数 ＞ SEO ＞ 統一性 ＞ 収益。§1-1 のみで定義する。

**D-06-06 細かな衝突の修正**
* 決定：まとめの直前にはCTAを置き、広告はH2の間に置く。コード例は「再現に必要な部分は省略せず、無関係な部分は載せない」。文章だけの記事は「原則として図・表・コードのいずれかを含める」。実行モードに「記事構成作成モード」を加える（順序：SEO分析 → 構成作成 → 品質診断 → 改修／新規作成 → HTML出力）。

**D-06-07 未作成文書の扱い**
* 決定：「AI実行テンプレート」「HTMLルール」は未作成の文書として明記する。付録Aの「既存実装」という判断基準の記述は削除する。

**D-06-08 評価項目の一本化**
* 決定：評価項目は品質基準の9分類を唯一の定義とし、REQUIREMENTS §7-13 と DATABASE §34-2 は品質基準を参照する。

**D-06-09 品質基準のバージョン管理**
* 決定：品質基準（共通基準・ブログ別の定義）にバージョン番号と変更履歴を付け、評価結果には評価時のバージョンを記録する。

**D-06-10 複数ブログへの適用**
* 決定：品質基準を**共通基準**（基本原則、採点の仕組み、SEO・UX・収益の倫理、AIの実行ルール）と**ブログ別の定義**（想定読者、コンセプト、記事種類、CTAの種類、ロードマップの構成、HTMLルール、採点項目の適用可否）に分ける。現行の内容は、共通基準とIT技術ブログ用の定義に分割する。
* 理由：現在はIT技術ブログのみだが、今後追加するブログは異なるジャンルになる想定のため。
* 影響：品質基準全体、REQUIREMENTS §6-10、DATABASE

---

### 項目7：BlogOSのAI機能

**D-07-01 AIの自動実行範囲**
* 決定：

| 段階 | 内容 | 自動実行 |
| --- | --- | --- |
| 0：分析・提案 | 品質診断、SEO分析、構成案、改善案 | 可（人の操作をきっかけに） |
| 1：編集案の作成 | 編集案テーブルにだけ書き込む | 可（人の操作をきっかけに） |
| 2：WordPressへの反映 | 下書きとして更新など | 不可。必ず人が承認する |
| 3：重大な操作 | 公開・削除・WordPressの設定変更 | 不可。人が承認し、さらに確認画面を挟む |

* AIが作成した記事の自動公開は**行わない**（WORDPRESS_API §110-11 を確定）。
* 影響：REQUIREMENTS §12-3・§12-6、ARCHITECTURE §18、WORDPRESS_API §110-11、DEVELOPMENT_RULES §55・§240・§321

**D-07-02 AIを実行するきっかけ**
* 決定：人が画面で実行したときだけ動かす。定期実行はしない。（2026-09-26 に D-25-02 で変更：まとめて実行と、有効にした場合だけの条件による自動の再評価（品質診断だけ）を加えた）
* 理由：定期実行で記事が更新され、再評価が必要になるケースは基本的にないため。

**D-07-03 AIの実行回数と受け入れ**
* 決定：
  * 1回の指示につき1回だけ実行する（繰り返し回数は設定値で、初期値は1）。
  * AIの出力を受け入れるかは人が判断する（許容ラインは設定値で、初期値は90点）。受け入れた場合は、人が直すか、不足点だけを直すようAIに依頼する。受け入れない場合は作り直すか破棄する。
  * 公開には、人が「必須条件をすべて満たし、95点以上」と確定することが必要（D-06-02）。
  * 「動作確認済み」など、AIでは判定できない項目は「要人間確認」としてAIの採点から外す。画面には「AIの点数」と「人が確定した点数」を別々に表示する。
  * 評価結果には、評価した主体（AI／人）、項目ごとの判定、品質基準のバージョンを記録する。
* 理由：毎回繰り返し実行すると費用がかさむため。
* 影響：品質基準 §10-1・§10-4・§12-6、DATABASE §34

**D-07-04 AIの入出力の保存**
* 決定：`ai_generations` に、実行方式、提供元、モデル、推論の深さ、AI実行テンプレートのIDとバージョン、品質基準のバージョン、入出力の全文、トークン数・費用の目安、状態、エラー、関連する編集案・評価へのひも付けを保存する。採用された出力は無期限、採用されなかったものは1年保存する。認証情報と、保存しないと決めた個人情報はAIに送らない。
* 影響：DATABASE §35・§44-3

**D-07-05 テンプレート・品質基準の置き場所**
* 決定：AI実行テンプレート、品質基準（共通基準・ブログ別の定義）の正本は、リポジトリ内のファイル（例：`resources/ai/templates/`、`resources/quality/common/`、`resources/quality/blogs/{ブログ}/`）とし、Gitで管理する。`docs/BLOGOS_QUALITY_STANDARD.md` は案内の文書にする。
* 理由：BlogOSが実行時に読み込んでAIに渡すものであり、バージョン管理が必要なため。

**D-07-06 実行方式（手動／API）**
* 決定：
  * 当面は手動実行を標準とする（BlogOSが指示文を作成 → 利用者がChatGPTに貼り付け → 回答をBlogOSに貼り付け → BlogOSが保存・反映）。
  * 最初から、実行モードごとに手動とAPI実行を切り替えられる設計にする（共通のインターフェースと2つの実装）。
  * 利用予定のサービスはChatGPT（OpenAI）。ChatGPTの契約とAPIの契約は別物であり、API実行にはOpenAI APIの契約とAPIキーが必要。API契約は切り替え処理を実装する時期に行う。
  * API実行時の標準モデルは設定で指定する（コードに直接書かない）。
* 影響：REQUIREMENTS §12-2、ARCHITECTURE §18

**D-07-07 生成方法の記録**
* 決定：
  * AI実行ごとに、実行方式（`api` / `manual`）、提供元、モデル（API実行では応答に含まれる正確なモデル名。手動実行では利用プランと画面に表示されたモデル名を選択または入力）、推論の深さ、テンプレートと品質基準のバージョンを記録する。
  * AIを使わない記事は「人（AI不使用）」と記録する。
  * 編集案には、AIの出力のまま反映したか人が修正したかと、修正の量を記録する。
  * 記事からは「記事 → 反映記録 → 編集案 → AI実行記録」のひも付けで、どの版をどの方法で作ったかを時系列でたどれるようにする。
* 理由：モデルの世代交代（例：gpt-6 → gpt-7）や、手動と自動の違いが評価に与える影響を比較できるようにするため。
* 影響：DATABASE §35

**D-07-08 費用と実行の制限**
* 決定：月の費用上限と1回あたりのトークン上限を設定値として持つ。AIの呼び出しはJobとして実行する。
* 参考：作業ごとの標準モデルは gpt-6-sol、軽い作業は gpt-6-luna、gpt-6-astra は人が選んだときだけ（2026-09時点の料金による試算で、月80回の実行につき約$10〜20）。
* 影響：ARCHITECTURE §18

**D-07-09 「AI」の呼び分け**
* 決定：「BlogOSのAI機能」（BlogOSに組み込む機能）と「開発支援AI」（Claude Code等）を呼び分ける。
* 影響：DEVELOPMENT_RULES §204〜§210・§365〜§367、CLAUDE.md

---

### 項目8：記事の管理情報とGoogleデータの対応付け

**D-08-01 「記事」の参照方法**
* 決定：記事単位のテーブル（編集案、管理情報、評価、AI実行記録、反映記録、記事内メディア等）は、`post_id` と `page_id` をどちらもNULL許容で持ち、「必ずどちらか一方だけに値が入る」制約を付ける。
* 理由：ロードマップ記事が固定ページで作られる可能性があり、ポリモーフィック関連では外部キー制約を付けられないため。

**D-08-02 記事の管理情報**
* 決定：`article_managements`（記事1件につき1行）に、`blog_id`、`post_id` / `page_id`、`article_type`、`article_subtype`、`main_search_intent`、`sub_search_intents`、`work_status`、`memo` を持つ。記事種類の選択肢はブログ別の定義に従い、DBでは文字列で持つ。

**D-08-03 キーワード**
* 決定：別テーブル `article_keywords`（キーワード、主／副）で持つ。

**D-08-04 記事同士の関係と内部リンク**
* 決定：設計上の関係（人が登録する）を `article_relations`、本文から同期時に抽出する実際の内部リンクを `internal_links` として分けて持つ。「内部リンク分析」を将来の拡張から外し、コンテンツ管理の段階に入れる。
* 影響：REQUIREMENTS §10・§11

**D-08-05 Googleデータと記事の対応付け**
* 決定：`posts` / `pages` に、WordPressの `link` から作った正規化済みのパスを持たせる。Googleのデータは受け取ったURLをそのまま保存し、対応する記事（`post_id` / `page_id`）を解決できれば設定する。過去のURL（履歴）でも照合できるようにする。粒度と保存期間はGoogle連携の設計時に決める。
* 影響：DATABASE §36

**D-08-06 編集案・反映記録の固定ページ対応**
* 決定：D-08-01 に合わせ、編集案テーブルと反映記録テーブルにも `page_id` を持たせる。

---

### 項目9：削除

**D-09-01** WordPress側での「ゴミ箱に移動」は、ステータスの変更（`status = trash`）として扱い、履歴に残す。

**D-09-02** WordPress側での完全削除（ゴミ箱を含むIDの一覧から消えたもの）は、専用の列 `wordpress_deleted_at` で論理削除し、履歴と `sync_issues` に記録して通知する。評価・AI出力・管理情報は残す。Laravel標準の `deleted_at` は使わない。

**D-09-03** 削除の判定は、IDの一覧をエラーなく最後まで取得できた場合だけ行う。一度に一定の割合（初期値10%）を超えて消えた場合は削除として扱わず、`sync_issues` に登録して人の確認を待つ。

**D-09-04** カテゴリ・タグ・メディアの削除を検知したら、DB上でそれに関連していた投稿を詳細まで再取得する（関連の付け替えでは投稿の `modified_gmt` が変わらないため）。

**D-09-05** BlogOSから削除する場合、投稿・固定ページは既定で「ゴミ箱に移動」とし、完全削除（`force=true`）はさらに確認画面を挟んだ場合だけ行う。カテゴリ・タグ・メディアは常に完全削除になるため、必ず確認画面を挟む。いずれも反映記録を通して実行する（D-07-01 の段階3）。

**D-09-06** BlogOSでのブログ削除は2段階とする。既定はアーカイブ（`blogs.archived_at`。同期・反映・AIの対象外にし、元に戻せる）。完全削除は別の操作とし、確認のためにブログのURLを入力させる（登録時と同じ正規化処理を通して照合する）。いずれもWordPress側には何もしない。

**D-09-07** 外部キーの削除時の動作は、`blogs` に属するテーブルは CASCADE、WordPress由来のデータ同士は RESTRICT、NULLを許す参照（`author_id` 等）は SET NULL とする（WordPress IDの列は受け取ったとおりの値を残す）。

**D-09-08** 編集案は「破棄」の状態にするだけで行は残す。評価・AI出力・同期の記録は、保存期間を過ぎたら定期処理で物理削除する。

* 影響（項目9全体）：REQUIREMENTS §7-1、DATABASE §26-2・§31・§44-5、WORDPRESS_API §41、DEVELOPMENT_RULES §238・§239

---

### 項目10：細かな不足事項

**D-10-01 WordPressのサイト設定**
* 決定：WordPress由来の設定は、別テーブル `blog_settings`（`blog_id`、キー、値）に、あらかじめ決めたキーだけを保存し、履歴テーブルを持つ。保存するキーは `title`、`description`、`url`、`home`、`timezone_string`、`gmt_offset`、`date_format`、`time_format`、`language`、`posts_per_page`、`show_on_front`、`page_on_front`、`page_for_posts`、`default_category`、`site_icon`、`site_logo`。`blogs` にはBlogOS側で管理する情報だけを残す（登録したURL、BlogOS上の表示名、`archived_at`、`is_selected`）。DATABASE §5 の `blog_histories` は整理する。
* → 「登録したURL」は D-13-01 により `blogs.home`（ホームURL）に変更。
* 影響：REQUIREMENTS §6-1、DATABASE §4・§5、WORDPRESS_API §11

**D-10-02 メディア**
* 決定：`alt_text`、`width`、`height`、`filesize`、`sizes`（JSON）、`mime_type`、`media_type`、`source_url` を持つ。添付先は `wordpress_post_id` として受け取ったとおりに保存し、記事を解決できれば `post_id` / `page_id` を設定する。本文中で使われているメディアは `article_media`（同期時に本文から抽出）とし、`post_media` は廃止する。
* 影響：DATABASE §18・§20、WORDPRESS_API §20

**D-10-03 APIのレスポンス原文**
* 決定：API確認画面は表示のたびにAPIを呼び出して表示し、原文はDBに保存しない。ただし、同期・反映でエラーになった場合の応答本文は**全文を**、`sync_issues` または反映記録に保存する（リクエストの `Authorization` ヘッダーは保存しない）。REQUIREMENTS §7-3 の「APIレスポンス保持」は「DTOがメモリ上で保持する」意味に書き直す。
* 理由：切り詰めると、原因の特定に必要な部分が失われる可能性があるため。
* 影響：REQUIREMENTS §7-3、DATABASE §44-7、WORDPRESS_API §43-3・§71・§110-8

**D-10-04 カスタム投稿タイプ・カスタムタクソノミー**
* 決定：
  * REST APIで公開されているものは**すべて同期の対象**とする。WordPress内部の種類（`wp_` で始まる投稿タイプとタクソノミー、`nav_menu_item`、`nav_menu`）は対象外とし、`attachment` は `media` で扱う。公開されていないものは「REST APIで非公開」と表示する。
  * 共通テーブル `custom_contents`（`type` で区別。列構成は `posts` と同じ）、`custom_terms`（`taxonomy` で区別）、`custom_content_terms` と、それぞれの履歴テーブルに保存する。一意キーは `blog_id + wordpress_id`。
  * **同期と閲覧だけ**とし、編集案・反映・品質評価の対象外とする。「記事」の定義（投稿と固定ページ）は変えない。
* 理由：WordPress APIの利用自体に費用はかからず、確認が主目的で変更は基本的に発生しないため。
* 影響：DATABASE、WORDPRESS_API §29・§30・§110-2・§110-3

**D-10-05 APIの対象範囲**
* 決定：WORDPRESS_API §61 を「対象」（API Root、Settings、Posts、Pages、Categories、Tags、Users、Media、Statuses、Types、Taxonomies、カスタム投稿タイプ／タクソノミー）と「将来の候補」（Comments、Search、Revisions、Block関連、Themes、Templates、Navigation、Global Styles、プラグイン独自のAPI）に分ける。
* 影響：WORDPRESS_API §61・§88・§89

---

### 項目11：実装上のルール

**D-11-01 Repository** 例外は設けず、DBへのアクセスはすべてRepositoryを経由する。Bladeの中での遅延読み込みは禁止し、開発環境では `Model::preventLazyLoading()` で検出する。（ARCHITECTURE §9-3、DEVELOPMENT_RULES §47）

**D-11-02 Controllerの名前空間**

| 名前空間 | 用途 |
| --- | --- |
| `Controllers\WordPressApi` | API確認画面 |
| `Controllers\Database` | DB確認画面 |
| `Controllers\Api` | BlogOS自身がJSONを返すエンドポイント（同期状態の取得など） |
| 機能ごとの名前空間 | 業務画面 |

ルート名の先頭とビューのディレクトリも `wp-api.` / `wordpress-api/`、`database.` / `database/` に揃える。（DEVELOPMENT_RULES §141〜§143）

**D-11-03 ディレクトリ構成** `app/Clients/{WordPress,OpenAi}/`、`app/DTO/WordPress/`、`app/Services/{機能}/`、`app/Repositories/`、`app/Enums/`、`app/Jobs/`、`app/Ai/`。（ARCHITECTURE §22）

**D-11-04 ルート名とController** ルート名はドット区切りで `機能.操作`（例：`posts.index`、`wp-api.categories.show`、`database.categories.index`）。Controllerは画面ごとではなくリソースごとに作り、`index` / `show` 等で操作を分ける。（CLAUDE.md §20、DEVELOPMENT_RULES §31・§70）

**D-11-05 用語集** ARCHITECTUREに用語集を設け、次の用語を定義する。
* API確認画面：WordPress APIをその場で呼び出して結果を表示する画面
* エンドポイント一覧：BlogOSが対象とするWordPress APIの一覧
* DB確認画面：BlogOS DBの保存内容・履歴・テーブルの状態を表示する画面
* 業務画面：記事管理・編集案・同期・評価などの画面

**D-11-06 コメントの言語** 日本語で書く。「既存の方針に合わせ」という根拠の記述は削除する。（DEVELOPMENT_RULES §283）

---

### 項目12：文書体系

**D-12-01 文書の担当範囲と優先順位**
* 決定：CLAUDE.md の1か所だけで定義し、他の文書は参照する。

| 優先度 | 文書 | 担当範囲 |
| --- | --- | --- |
| 1 | REQUIREMENTS | 何を実現するか |
| 2 | ARCHITECTURE | 構造、データの流れ、用語集 |
| 3 | DATABASE ／ WORDPRESS_API（同格） | DBはデータの保存方法、APIはWordPressとの通信方法 |
| 4 | DEVELOPMENT_RULES | 実装の方法 |
| 別枠 | 品質基準（`resources/quality/`） | 記事の品質だけを扱う |
| 別枠 | CLAUDE.md | Claude Codeの作業手順（仕様は定義しない） |
| 例外 | WordPress・Laravelの公式仕様 | それらの製品の挙動に関する事実は公式仕様を優先する |

品質基準の「最上位」は「記事品質に関する基準」に、CLAUDE.md の「最上位の作業ルール」は「Claude Codeの作業手順の基準」に書き換える。

**D-12-02 監査結果の分類と開発時の優先順位**
* 監査結果の分類：一致 / 不足（未実装）/ 相違 / バグ / 過剰（不要・重複）/ 設計上の懸念 / 要確認（CURRENT_STATUSでも使う）。
* 開発時の優先順位（DEVELOPMENT_RULESのみで定義）：正確な仕様理解 ＞ データ整合性 ＞ セキュリティ ＞ 設計との一貫性 ＞ 保守性 ＞ 可読性 ＞ テスト容易性 ＞ 拡張性 ＞ パフォーマンス ＞ UI ＞ 実装速度。

**D-12-03 重複の削減**

| 規則 | 定義する場所 |
| --- | --- |
| 参照元・正本・AIの段階 | ARCHITECTURE |
| ID命名・履歴・削除・保存期間 | DATABASE |
| 取得条件・差分判定・反映手順 | WORDPRESS_API |
| エラー処理・セキュリティ・テスト・Git | DEVELOPMENT_RULES |
| 現在実装との関係・文書の役割分担 | CLAUDE.md |

DEVELOPMENT_RULESは重複を削り、章ごとにまとめ直す（目安は現在の半分程度）。WORDPRESS_APIのWordPress API解説は参考として残す。

**D-12-04 決定記録と版管理** 本書（`BLOGOS_DECISIONS.md`）を設け、各設計書の冒頭にバージョンと最終更新日を付ける。

**D-12-05 技術スタック**
* 決定：PHP 8.3以上、Laravel 13系。DBは開発環境・XServerともにMySQL（`DB_CONNECTION=mysql`）。非機能要件の章に記載する。
* 確認事項：XServer上のDBエンジン（MySQL / MariaDB）と正確なバージョン、PHPのバージョン。
* 補足：`.env.example` は `DB_CONNECTION=sqlite` のままのため、CURRENT_STATUSで差異として扱う。

**D-12-06 設計書修正の進め方**
* ① 本書を作成 → ② REQUIREMENTS → ARCHITECTURE → DATABASE → WORDPRESS_API → DEVELOPMENT_RULES → CLAUDE.md の順に修正 → ③ 品質基準を共通基準とIT技術ブログ用の定義に分割 → ④ CURRENT_STATUSを作成 → ⑤ 実装の優先順位を決定。
* 各文書を修正するたびに差分を示して確認を得る。細かな文言の修正はまとめて行い、判断が必要な箇所だけ確認する。

---

### 項目13：DB設計書の改訂時に追加で決めた事項（2026-09-24）

DB設計書をv2.0.0に改訂する際、既存の決定を具体化するために次の事項を決めた。

**D-13-01 ブログの識別にホームURLを使う**
* 決定：`blogs` にはブログのホームURLを `home` として持ち、UNIQUEとする。WordPressの「サイトアドレス」（`home`）を使い、「WordPressアドレス」（`url`）は使わない。登録時はAPI Discoveryで得た `home` を正規化して保存する。正規化では、スキームの違い・ホスト名の大文字小文字・末尾のスラッシュを吸収する。同期で `home` の変更を検知した場合は自動更新せず、`sync_issues` に登録して人が確認する。
* 理由：WordPressのREST APIのURLはサイトアドレスを基準に作られるため。対象ブログでは `url` が `http://`、`home` が `https://` になっており、APIで使うのは `home` の方であるため。同じブログの二重登録を防ぎ、完全削除時のURL照合（D-09-06）にも使うため。
* 影響：DATABASE 5-2・11-3・13-4、REQUIREMENTS 6-1

**D-13-02 ブログに適用する品質基準** `blogs.quality_profile` に、適用するブログ別の定義（`resources/quality/blogs/{識別子}`）を持つ。（DATABASE 5-2）

**D-13-03 カテゴリ・タグの投稿数** WordPressの `count` は保存しない。関連テーブルから算出でき、保存すると投稿が増えるたびに履歴が増えるため。（DATABASE 6-3）

**D-13-04 新規作成・削除の履歴** 新規作成（初回取得を含む）は `field = __created`、完全削除の検知は `__deleted`、復活は `__restored` の1行だけを記録する。初回取得で全レコード×全項目の履歴ができることを避けるため。（DATABASE 8-2）

**D-13-05 関連の変更の履歴** 投稿のカテゴリ・タグの付け替えは、`post_histories` に項目名 `categories` / `tags`、値はWordPress IDの一覧として記録する。（DATABASE 6-1）

**D-13-06 履歴の実行者** 履歴に `user_id` を持ち、手動編集した利用者・反映を承認した利用者を記録する（D-02-07 の「実行者」を具体化）。（DATABASE 8-2）

**D-13-07 反映記録の対象** 反映記録は記事以外（カテゴリ・タグ・メディア）も対象とし、`post_id`・`page_id`・`category_id`・`tag_id`・`media_id` のうち多くとも1つに値が入る制約を付ける。（DATABASE 10-4）

**D-13-08 評価結果の持ち方** AIの評価と人の評価を別の行とし、人が確定したものを `is_confirmed` で区別する。編集案も評価できる。（DATABASE 9-5）

**D-13-09 未決定事項の追加** 「設定値（AIの実行方式、費用の上限、受け入れの目安の点数、削除判定の割合など）をconfigとDBのどちらに置くか」「履歴と評価結果の保存期間」を未決定事項とする。（DATABASE 16章）

---

### 項目14：品質基準の分割時に決めた事項（2026-09-24）

旧 `BLOGOS_QUALITY_STANDARD.md` を `resources/quality/` へ分割する際（D-06-10、D-07-05）、次の事項を決めた。

**D-14-01 識別子** IT技術ブログのブログ別の定義の識別子（`blogs.quality_profile` の値・フォルダ名）を `si-note` とする。

**D-14-02 ファイル構成** 共通基準を `common/` の4ファイル（principles・scoring・writing・ai-and-operation）、si-noteの定義を `blogs/si-note/` の5ファイル（profile・article-types・site-design・tech-content・html-rules）に分ける。BlogOSのAI機能に必要な部分だけを渡せるよう、話題ごとにファイルを分けた。`docs/BLOGOS_QUALITY_STANDARD.md` は案内だけとする。

**D-14-03 バージョン** 共通基準とブログ別の定義は、それぞれ独立してバージョンを管理する（初版はいずれも1.0.0）。採点項目のキー・配点・必須条件の変更はマイナーバージョン以上、文言だけの修正はパッチバージョンを上げる。

**D-14-04 必須条件** 必須条件を `req.no_misinformation`、`req.no_speculation`、`req.verified`、`req.title_match`、`req.not_orphan` の5つとする。ブログ別の定義で追加できる。`req.verified` と `req.not_orphan` は、ブログの性質上該当しない場合だけ対象外にできる。

**D-14-05 評価項目のキーと判定者** 9分類・49項目（合計100点）の採点項目にキー（例：`intent.main`）を付け、評価結果に記録する値とする。各項目に判定者（AI・人／人）を定め、「人」の項目はAIが「要人間確認」とする。

**D-14-06 対象外の項目の換算** ブログ別の定義で対象外とした採点項目は配点から除外し、残りの満点を100点に換算する。

**D-14-07 分類名の一般化** 共通基準では、分類②を「初心者でも理解できる」から「読者の理解しやすさ」（キー `reader.*`）に改めた。「初心者」はsi-noteの想定読者として定義する。

**D-14-08 改修範囲の定義** 軽微な改善（既定。見出しの構成を維持）／構成の見直し（見出しの変更可、主要な内容は維持）／全面改修（人が指定した場合だけ）と定義する。どの範囲でも誤った情報は必ず修正する。

**D-14-09 集客記事の収益導線の位置** 旧版の「まとめの前またはまとめの後」を、「まとめの直前」に統一する（D-06-06 の「まとめの直前はCTA」に合わせる）。

**D-14-10 AIが経験していないことを書かない** AIは「実際に試した」「実務で使っている」など、自らが経験していないことを事実として書かない。実体験・検証の内容は、人が提供したものだけを使う。

* 影響：`resources/quality/` 全体、CLAUDE.md 3-1・4章

---

### 項目15：改訂後の設計書の整合性の見直し（2026-09-25）

改訂後の設計書（docs/ 7ファイル、CLAUDE.md、resources/quality/ 10ファイル）を突き合わせ、見つかった不備を次のとおり決めた。

**D-15-01 点数と判定の範囲** 点数は、対象項目で100点に換算した値（小数第1位まで）とする。判定は「95.0点以上／90.0点以上95.0点未満／90.0点未満」とする。DBの `score` も小数第1位とする。（→ D-06-02 の判定表の表記を置き換える）（quality `common/scoring.md` 4〜5章、DATABASE 9-5）

**D-15-02 記事種類単位の対象外** 採点項目の対象外を、ブログ単位に加えて記事種類単位でも定められるようにする。si-noteでは、親ロードマップの `nav.parent`、ロードマップ記事の `coverage.common_problems`・`coverage.solutions`・`reader.try_it` を対象外とし、記事種類ごとの `nav.parent`・`nav.child` の意味を定める。記事ごとに個別に対象外とすることはできない。（quality `common/scoring.md` 4章、`blogs/si-note/article-types.md` 6-1）

**D-15-03 競合の解消** 競合は、人が「WordPressの変更を取り込む（編集案の基準の版を更新）」「編集案を破棄する」「編集案で上書きする（確認画面あり）」のいずれかを選んで解消する。（WORDPRESS_API 21-2、ARCHITECTURE 3-3、DATABASE 9-1）

**D-15-04 BlogOS独自データの履歴** `article_draft_histories` と `article_management_histories` を設ける。キーワードと記事同士の関係の変更は `article_management_histories` に記録する。変更元 `blogos_manual`・`ai` の記録先を明確にする。（DATABASE 4章・8-3・9-1・9-2、REQUIREMENTS 7-15）

**D-15-05 カテゴリ・タグ・メディアの反映** これらは編集案を持たない。承認時の入力内容を `request_summary` に、画面を開いた時点の値を `base_values` に保存し、反映直前に最新の値と比べて競合を確認する。（WORDPRESS_API 21-3、DATABASE 10-4、ARCHITECTURE 13-5）

**D-15-06 重複の解消** REQUIREMENTS 3-3・5-2・9-6 の表を要件の文に改め、定義はARCHITECTURE（3-2・3-4・18-2）を参照する。ARCHITECTURE 25章のセキュリティの表を方針だけにし、詳細はDEVELOPMENT_RULES 13章で定義する（Application Passwordの発行ルールをDEVELOPMENT_RULES 13-2 に移した）。

**D-15-07 実行契機 `recovery`** 回復処理は通常、定期同期・手動同期の最初に行い、実行契機はその同期のものとする。`recovery` は回復処理だけを単独で手動実行した場合に使う。（WORDPRESS_API 12章、DATABASE 10-1）

**D-15-08 同期の問題の重複防止** 同じ対象・同じ種類の未解決の `sync_issues` がある場合は、新しい行を作らず既存の行を更新する。（DATABASE 10-3、WORDPRESS_API 15-4）

**D-15-09 未解決の参照の再照合** 記事の新規作成・`link` の変更・メディアの新規作成時に、未解決の内部リンク・本文中のメディアを再照合する。（DATABASE 7-1、WORDPRESS_API 15-5）

**D-15-10 列挙値のキー** `article_managements.work_status`（`not_started` / `needs_revision` / `in_progress` / `in_review` / `done`）と `article_relations.relation_type`（`parent` / `child` / `previous` / `next` / `related` / `advanced` / `comparison` / `troubleshooting` / `monetization`）の値を定義する。各ブログでの意味はブログ別の定義で定める。（DATABASE 9-2・9-4、quality `blogs/si-note/site-design.md` 2-1）

**D-15-11 品質基準のバージョンの揃え方** 共通基準の全ファイルは常に同じバージョンにそろえる。ブログ別の定義のフォルダ内も同様とする。（quality `README.md` 4章）

**D-15-12 その他の表記** 決定記録の節番号の注記を「項目1〜12は改訂前、項目13以降は改訂後」に改める。CLAUDE.md 3-3 の「命名」を「クラス・ルート等の命名（DBの列名はDATABASE）」に改める。ARCHITECTURE 29章の用語集に「ホームURL」「必須条件」「改修範囲」「品質基準のバージョン」を追加する。

**D-15-13 必須条件と重複する採点項目の置き換え** 必須条件を満たせば常に満点となり、差がつかない4項目を置き換える。配点は変えない（合計100点）。

| 旧 | 新 | 配点 |
| --- | --- | --- |
| `accuracy.verified`（`req.verified` と重複） | `accuracy.environment_shown`：検証した環境・バージョン・確認した日付を明記 | 3 |
| `trust.verified_content`（`req.verified` と重複） | `trust.sources_cited`：根拠となる公式情報・出典を示している | 1 |
| `trust.code_checked`（`req.verified` と重複） | `trust.limitations`：前提条件・制約・できないことを明記 | 1 |
| `trust.no_guess`（`req.no_speculation` と重複） | `trust.balanced`：デメリット・リスクも示している | 1 |

新しい4項目はいずれも判定者を「AI・人」とし、採点項目のうち判定者が「人」だけの項目（要人間確認）は9から6に減る（`intent.competitors`、`accuracy.official`、`accuracy.up_to_date`、`original.experience`、`ux.mobile`、`trust.official_checked`）。品質基準はまだ使用していないため、共通基準のバージョンは1.0.0のまま確定する。（quality `common/scoring.md` 3章）

---

### 項目16：現在の実装の調査で決めた事項（2026-09-26）

`BLOGOS_CURRENT_STATUS.md` の要確認事項への回答として、次の事項を決めた。

**D-16-01 テーマの切り替え** 画面の見た目をテーマとして切り替える機能（`config/blogos.php` の `theme`、`resources/views/themes/{テーマ名}/`）を残す。当面は `blank`（装飾なし）で機能を作り、システムの構築後に `ironman` を作り込み、その後も他のテーマを追加できるようにする。テーマは見た目だけを担当し、データ・業務処理・ルートはテーマによって変えない。（ARCHITECTURE 6-1・22章、REQUIREMENTS 13-1）

**D-16-02 Google連携の試作** 現在のGoogle連携（GA4・Search Console・AdSenseのAPI確認画面）は試作とし、分析機能の段階で設計に沿って作り直す。現在のコードはGitの履歴に残っている。（CURRENT_STATUS 3-11）

**D-16-03 公開リポジトリでの秘密情報** リポジトリは公開（Public）である。Gitの履歴に含まれた管理者のパスワードは漏えいしたものとして扱い、パスワードの変更を主な対策とする。初期状態のSeederで作られた Test User は削除する。（CURRENT_STATUS 2章 P0・P1）

---

### 項目17：ログインの認証情報と記録（2026-09-26）

**D-17-01 管理者の認証情報をソースコードに書かない** `AdminUserSeeder` は、管理者のメールアドレス・パスワードを環境変数（`ADMIN_EMAIL`・`ADMIN_PASSWORD`）から読む。パスワードの値は当面変更しない（利用者の判断）。（DATABASE 5-1、DEVELOPMENT_RULES 13-1）

**D-17-02 テスト用ユーザーを作らない** `DatabaseSeeder` から Test User の作成を削除する。既に作成された Test User（ローカル・XServerとも）は、Migrationで削除し、XServerでも再発しないようにする。（DATABASE 5-1）

**D-17-03 ログイン履歴** ログインの成功・失敗とログアウトを `login_histories` に記録する（パスワードは記録しない）。管理者のパスワードを当面変更しないため、不正なログインの有無を確認できるようにする。保存期間は1年。（DATABASE 5-1・14章、REQUIREMENTS 13-3、DEVELOPMENT_RULES 13-1）

**D-17-06 ログインの試行回数の制限** 同じメールアドレスとIPアドレスの組み合わせで5回失敗したら、1分間ログインを止める（Laravel標準のRateLimiter）。止めた試行は `login_histories` に `login_locked` として記録する。管理者のパスワードが公開リポジトリの履歴に残っているため。（2026-09-26。DATABASE 5-1、DEVELOPMENT_RULES 13-1）

**D-17-04 開発の順序** 開発フェーズは要件定義書 11章の順（基盤 → コンテンツ管理 → 分析（Google）→ 品質評価・AI）とする。開発支援AIを使える約1か月で主要機能が間に合わない場合は、契約を継続して作り込む。（`BLOGOS_IMPLEMENTATION_PLAN.md`）

**D-17-05 XServerの現状** XServerには配置済みで、MySQLも用意されている。Seederはローカルと同じもので、`blogs`・`blog_histories` のMigrationは動作確認済み。ローカルと異なる部分がある。（`BLOGOS_IMPLEMENTATION_PLAN.md`）

---

### 項目18：段階2の実装で決めた事項（2026-09-27）

**D-18-01 ブログ登録の手順** 登録は1回の送信で行う。サーバー側で WORDPRESS_API 29章の確認（API Discovery、ホームURLの確定と重複の確認、認証の確認、必要なエンドポイント、WordPress側の拡張の判定、サイト設定の取得）を行い、すべて通った場合だけ保存する。確認結果は登録後の詳細画面に表示する。パスワードをセッション等に保持しないため、確認画面を挟む2段階の形にはしない。

**D-18-02 登録時の認証情報は必須** 同期は `context=edit` で行う（D-04-06）ため、ブログ登録時に認証情報を必須とする。

**D-18-03 .env の共通認証情報の廃止** `WP_APP_USER`・`WP_APP_PASSWORD`（`config/services.php` の `wp`）は使わない。ブログごとの認証情報（`blog_credentials`）だけを使う（D-03-02）。

**D-18-04 API Rootの探し方** `<入力URL>/wp-json/` で見つからない場合は、入力URLのHTMLの `<link rel="https://api.w.org/">` から探す。以後の通信は、確定したホームURL＋`/wp-json` を使う。

**D-18-06 ローカル専用の信頼する証明書** ローカルPCではNortonのウェブシールドがHTTPS通信を検査するため、PHP（Herd）の標準の証明書リストでは外部サイトの証明書を検証できない。証明書の検証は止めずに、Herdの証明書リストにNortonのルート証明書を加えたファイル（リポジトリの外。例：`C:\Users\shinr\.config\blogos\ca-bundle-local.pem`）を作り、ローカルの `.env` の `HTTP_CA_BUNDLE` で指定したときだけ、HTTP通信の検証に使う。XServerでは設定しない。（config/blogos.php、AppServiceProvider、DEVELOPMENT_RULES 14章）

**D-18-05 旧画面の削除** 旧ブログ登録画面（`Database\BlogRegisterController`、iframeのポップアップ）と、ルートのなかった旧ブログ変更履歴詳細を削除した。ブログが1件もないときは、トップページからブログ登録へのリンクで誘導する。

### 項目19：段階3（同期の仕組み）の実装で決めた事項（2026-09-26）

**D-19-01 削除判定の最小件数** 一覧から消えた件数が2件（`config/blogos.php` の `sync.mass_deletion_minimum`）に満たない場合は、割合（D-09-03）に関係なく削除として扱う。投稿者・定義情報など件数の少ないリソースでは、1件の削除でも割合の上限を超えてしまうため。

**D-19-02 定義情報の `show_in_rest` は保存しない** `/wp/v2/types`・`/wp/v2/taxonomies` はREST APIで公開されているものだけを返すため、`types`・`taxonomies` に `show_in_rest` の列を持たない。（DATABASE 7章）

**D-19-03 日時のタイムゾーン** DBにはUTCで保存する（`config/app.php` の `timezone` は `UTC` のまま）。画面では表示用のタイムゾーン（`config/blogos.php` の `display_timezone`。初期値 `Asia/Tokyo`）に変換して表示し（`App\Support\DisplayTime`）、定期実行の時刻もこのタイムゾーンで指定する（毎日の同期は日本時間3:00）。段階3より前に作った画面（ログイン履歴など）は、作り直す際に合わせる。

**D-19-04 サイトアドレスの変更の記録** 同期で、WordPressのサイトアドレス（`home`）が `blogs.home` と異なることを検知した場合は、`sync_issues` に `home_changed` として記録する（D-13-01 の具体化）。

**D-19-05 `sync_issues` の対象と検出日時** 対象は `resource_type` と `resource_key`（WordPress IDまたはslug。参照先の未解決では「WordPress ID:参照の種類」）で表す。同じ問題を1行にまとめる（D-15-08）ため、`first_detected_at`・`last_detected_at` を持つ。

**D-19-06 旧 `categories` の作り直し** 旧設計の `categories`・`category_histories` は、新しい構造のテーブルに作り直した（旧データは同期で取り込み直す）。

**D-19-07 同期の実行方式** 同期はブログ単位のロック（キャッシュのロック、1時間）で重複を防ぐ。Jobは時間の上限30分・再試行なし（失敗は次の同期で取り込む）。「今すぐ同期」はJobとして登録し、Queueの処理が始まるまでの「開始待ち」はキャッシュに記録して画面に表示する。開始待ち・実行中は、重ねて登録しない。画面は開始待ち・実行中の間だけ `api.sync.status` を5秒ごとに読む。

**D-19-08 投稿のカテゴリ・タグの履歴** 投稿のカテゴリ・タグの変更は、DBに存在して実際に関連付けたものどうしで比べる。DBにないカテゴリ等を含む投稿で、取得のたびに履歴が増えることを防ぐため。

**D-19-09 同期の画面** 「今すぐ同期」と同期の問題の画面は `Controllers\Sync`（ルート名 `sync.*`）、同期の記録とWordPress由来のテーブルの閲覧は DB確認画面（`Controllers\Database`、ルート名 `database.sync-runs.*`・`database.wordpress-records.*`）とする。同期の問題は、画面で対応した内容を記録して解決済みにする。

### 項目20：段階4（記事管理と反映）の実装で決めた事項（2026-09-26）

**D-20-01 CHECK制約を付ける列の削除時の動作** MySQLでは、CHECK制約で使う列に ON DELETE SET NULL を付けられない。記事の参照（`post_id` / `page_id` 等の「どちらか一方」「多くとも一方」）と反映記録の対象の列は CASCADE とする。投稿・固定ページは同期で物理削除しない（論理削除）ため、CASCADEが働くのはブログの完全削除のときだけである。（DATABASE 11-2）

**D-20-02 編集案のカテゴリ・タグの列** 反映時に設定するカテゴリ・タグは `wordpress_category_ids` / `wordpress_tag_ids`（WordPress IDの一覧。JSON）、アイキャッチ画像は `wordpress_featured_media_id` とする（D-02-02 の命名）。既存記事の更新では、DBの現在の値から変わる項目だけを送る。

**D-20-03 WordPress側の拡張の判定結果の保存** 判定結果は `blog_credentials.connector_extension` に保存し、登録時・認証情報の更新時・接続確認時に更新する。

**D-20-04 競合の判定** 作業中の編集案がある記事は、WordPressの `modified_gmt` と編集案の基準の版（`base_wordpress_modified_gmt`）が異なる場合に競合とする（同期・反映直前とも同じ規則）。関連の付け替え（削除されたカテゴリ等による取得し直し）で版が変わらない場合は、競合とせずに取り込む。競合の解消の「編集案で上書きする」は、DBと基準の版を最新にしてから、通常の反映の確認画面へ進む。

**D-20-05 反映と同期の排他** 反映・競合の解消は、同期と同じブログ単位のロックの中で行う。同期中に反映しようとした場合は、待たずに（5秒で）中止し、画面で知らせる。回復処理は同期のロックの中で行う。

**D-20-06 HTTP 5xx の扱い** 反映の送信に対してWordPressが 5xx を返した場合は、処理が途中まで進んだ可能性があるため `unknown`（結果が不明）とし、人が確認する。4xx は `failed` とする。

**D-20-07 本文からの抽出の対象** 内部リンクは、ブログのホームURLと同じサイトの絶対URLと `/` で始まるパスを対象とし、管理画面・アップロードしたファイル・フィード等のパスを除く。本文中のメディアは、同じサイトの画像だけを対象とする（アフィリエイトの計測用の画像を除く）。抽出の仕組みを入れる前に取り込んだ記事は、`php artisan articles:extract` で抽出する。実データ（si-note）では、内部リンク509件のうち458件を記事に照合できた。照合できないものには、`xxx.html` の仮のリンク（37件）と、カテゴリのページへのリンクが含まれる。

**D-20-08 公開の確認** 反映の後に記事が公開の状態（`publish`・`future`）になる場合は、公開済みの記事の更新を含めて、承認とは別に「公開されることの確認」を求める（ARCHITECTURE 18-2 の段階3）。予約投稿（日時の指定）は、編集案の画面では扱わない。

**D-20-09 記事種類の選択肢** 記事種類・細分類は、ブログの品質基準のブログ別の定義（`article-types.md` の「1.」「1-1.」の表の値）から選ばせ、それ以外の値を受け付けない。定義がないブログでは自由入力とする。

**D-20-10 画面の構成** 記事・編集案・反映の確認・競合の解消は `Controllers\Articles`（ルート名 `articles.*`・`drafts.*`）、反映記録は `Controllers\Push`（`push-operations.*`）、カテゴリ・タグ・メディアの更新・削除は `Controllers\Terms`（`terms.*`）とする。反映の処理は、共通の処理（`PushOperationRunner`）・記事（`ArticlePushService`）・カテゴリ等（`TermPushService`）・回復処理（`PushRecoveryService`）に分ける。

**D-20-11 WordPress側の拡張のファイル** `Theme/Theme-SI-Original` に `functions.php` と `inc/blogos-connector.php` を作成した（このテーマのフォルダには、それまで `style.css` と `assets` だけがあり、Gitの管理下にない）。si-note は STINGER8 で運用しているため、拡張は無効のままである。

**D-20-12 実際の反映の確認** 実際のブログへの書き込みの確認は、既存の記事に触れず、テスト記事を公開しない方法（下書きの作成 → 更新 → ゴミ箱 → 完全削除）で行う。

### 項目21：段階5（Google連携の作り直し）で決めた事項（2026-09-26）

**D-21-01 認証方式** GA4・Search Console・AdSense とも OAuth（利用者のGoogleアカウントでのログイン）に統一する。BlogOSの画面からログインし、トークンは `google_accounts` に暗号化して保存する。サービスアカウントは使わない。OAuthの同意画面が「テスト」の状態では更新用のトークンが7日で失効するため、「本番」にしておく。

**D-21-02 GA4の粒度** ページ×日の指標に加えて、ページ×流入元（既定のチャネルグループ）×日の指標を保存する。ブログ全体の日ごとの指標も保存する（ユーザー数はページ単位の値を足し合わせられないため）。

**D-21-03 Search Consoleの粒度** ページ×日と、ページ×検索クエリ×日を保存する。ブログ全体の日ごとの指標も保存する（Googleがプライバシーのために省くクエリがあり、クエリ別の合計はページ別の合計と一致しないため）。

**D-21-04 AdSenseの粒度** ブログ（ドメイン）×日の収益の指標を保存する。AdSense APIがページ単位の集計を返す場合は、ページ×日も保存する（実装時に確認する）。

**D-21-05 保存期間** Googleのデータは無期限に保存する。量はDB確認画面で確認し、必要になったら見直す。

**D-21-06 Google APIの呼び出し方** Google APIは、WordPressと同じく、LaravelのHTTPクライアントで REST API を呼ぶ（`app/Clients/Google`）。Googleの公式ライブラリは独自のHTTPクライアントを使うため、テストで偽の応答に差し替えられず、証明書の設定（D-18-06）も効かないため。試作で使っていたライブラリ（`google/apiclient`、`google/analytics-data`）は、試作の削除とあわせて外す。

**D-21-07 取得の実行** Googleのデータは、毎日の同期の後（日本時間5:00）に、ブログごとにJobとして取得する。直近の数日は毎回取得し直す。初めて取得するときは、Search Consoleは16か月前から、GA4・AdSenseは同じ期間を取得する。

**D-21-08 過去のURLの照合（最後の部分）** URLから記事を探すときは、現在のパス、履歴の過去の link の順に照合し、それでも見つからない場合は、パスの最後の部分（例：`483.html`、スラッグ）が、ただ1つの記事の最後の部分と一致すれば、その記事とする。カテゴリ・タグ・投稿者・ページ送りのページは対象外とする。si-note ではカテゴリのスラッグを変更しており（`/javascript/form/483.html` → `/js/js-form/483.html`）、BlogOSが履歴を持つ前の過去のURLへのアクセス・リンクを照合するため。Googleの指標と内部リンクの両方に使う（`ArticlePathRepository`）。

**D-21-09 実データでの確認結果** si-note で、GA4（2026-03-19から）・Search Console（2026-03-21から）・AdSense（2025-05-26から）を取得した（初回は約2分）。AdSense のドメインでの絞り込み（`DOMAIN_NAME`）は使える。ページ単位の集計（`PAGE_URL`）は、APIが受け付けても1年分で0行であり、si-note では記事ごとの収益を取得できない。記事ごとの収益の表示は、データがある場合だけ行う（ブログ全体の収益は表示できる）。

### 項目22：段階6（品質評価・BlogOSのAI機能）の実装で決めた事項（2026-09-26）

**D-22-01 品質基準の読み込み** 採点項目・必須条件・配点・判定者・バージョンは、品質基準のファイルの表（キーを `...` で書いた行）から読み込み、コードに書かない。記事種類ごとの対象外は `article-types.md` の「記事種類ごとの採点の扱い」の表、ブログ単位の対象外は `profile.md` の「採点項目の適用」の節で「対象外」を含む行に書いたキーとする（`App\Services\Quality\QualityStandardLoader`）。

**D-22-02 AIの評価での判定者の扱い** AIの評価では、判定者が「人」の採点項目・必須条件を、AIの出力に関係なく「要人間確認」にする。ただし `req.no_misinformation` は「AIは指摘のみ」のため、AIが × とした場合は × を残す（誤りの可能性を人に示すため）。

**D-22-03 評価の確定** 確定できるのは、人の評価で、全ての採点項目と必須条件を ○・△・× で判定したものだけとする。AIの評価は、人が評価するときの初期値にできる（人が見直して、新しい評価として保存する）。

**D-22-04 手動実行の流れと記録** BlogOSが指示文を作る → 利用者がコピーしてChatGPT等で実行する → 回答と、使ったモデル（必須）・利用プラン・推論の深さを貼り付ける → BlogOSが取り込む。回答を読み取れない場合は失敗として記録し（回答は残す）、直した回答を貼り付け直せる。

**D-22-05 出力の形式** 品質診断はJSON（```json のコードブロック）、記事改修・新規記事作成は `=== 見出し ===` で区切った節（タイトル・スラッグ・抜粋・本文・変更点・自己評価・確認が必要な点）とする。長い本文をJSONの文字列にすると、エスケープの誤りが起きやすいため。SEO分析・構成作成は、回答を記録するだけとする（段階0：分析・提案）。

**D-22-06 改修の対象** 既存記事の改修は、作業中の編集案があればその編集案を対象にし（指示文にもその内容を渡す）、なければ記事から編集案を作って取り込む。AIの出力は、変更元 `ai` として編集案の履歴に残す。

**D-22-07 人の修正の量** AIの出力をもとにした編集案を人が修正したら、`human_edited` を true にし、`edit_ratio` を「AIが出力した本文と現在の本文の、行の違いの割合」（0〜1）とする。

**D-22-08 API実行** 実行方式を切り替える仕組み（共通のインターフェースと、手動・APIの2つの実装）を作った。API実行は、OpenAI API の契約の後に実装するまで使えない（実行方式を api にしたモードは、実行時にエラーにする）。

**D-22-09 AI実行テンプレート** `resources/ai/templates/` に、5つの実行モード（SEO分析・構成作成・品質診断・記事改修・新規記事作成）のテンプレート（1.0.0）と README を作成した。各テンプレートの冒頭で、指示文に含める品質基準のファイルを指定する。HTML出力のテンプレートは、`html-rules.md` ができてから作る。あわせて、共通基準の「AI実行テンプレート（未作成）」の記載を削除し、共通基準を 1.0.1 にした（文言の修正）。

**D-22-10 指示文の長さ** 実データ（si-note）での指示文は、約26,000〜55,000文字（品質診断 約35,000、記事改修 約55,000）。ChatGPTの入力の上限に当たる場合は、テンプレートの「品質基準のファイル」を減らして調整する（コードの変更は不要）。

**D-22-11 回答の貼り付け（2026-09-26）** (1) ローカルの `php artisan serve` は、決まった環境変数しか子プロセスへ渡さないため、Windowsでは `TEMP`・`TMP` が渡らず、PHPの一時フォルダが書き込めない場所になる。16KBを超える送信（日本語約5,000文字以上の回答の貼り付け）が「Unable to create temporary file」「POST data can't be buffered」で失われたため、`AppServiceProvider` で `TEMP`・`TMP` を渡す対象に加えた（XServerでは起きない）。(2) ChatGPTの画面からコピーした回答に入る出典の目印（`:contentReference[oaicite:0]{index=0}` など）は、読み取りの前に取り除く。

### 項目23：メタディスクリプション（2026-09-26）

**D-23-01 メタディスクリプションを扱う** 品質基準では、メタディスクリプションを全ての記事種類のテンプレートの2番目に置き（`article-types.md`）、書き方を定め（`writing.md` 3-2）、採点項目 `seo.meta_description`（2点）で評価する。WordPressの標準APIにはないため、SEOプラグイン AIOSEO が投稿・固定ページのAPIに加える項目から取得し、`posts`・`pages` に `meta_description_raw`（記事に設定した説明）と `meta_description_rendered`（実際に出力される説明）を保存する。si-note では、公開済みの投稿のうち10件（Excelの記事）が未設定で、AIOSEOが本文から自動で作った説明が出力されている。

**D-23-02 反映** 編集案のメタディスクリプションは、標準の投稿・固定ページのAPIに `aioseo_meta_data.description` として送る（WORDPRESS_API 31-1）。書き込めることは、si-note で下書きのテスト投稿を使って確認した（テスト投稿は完全に削除した）。

**D-23-03 編集案** 編集案に `meta_description` を持ち、記事から編集案を作るときは、記事に設定した説明を写す（未設定なら空）。空で反映すると、AIOSEOの自動の説明に戻る。

**D-23-04 BlogOSのAI機能** 指示文の記事の情報に、メタディスクリプション（未設定の場合は、自動の説明とともに未設定であること）を含める。記事改修・新規記事作成の出力に「=== メタディスクリプション ===」を加え（テンプレート 1.1.0）、品質診断では `seo.meta_description` をこの値で判定するよう指示する（テンプレート 1.0.1）。

**D-23-05 全件の取得し直し** 保存する項目を増やしたときのため、`php artisan blogs:sync --full`（更新日時に関係なく、全ての記事・メディアの詳細を取得し直す）を加えた。`--now`・`--full` での実行は、実行契機を「手動同期」として記録する。

**D-23-06 差分の判定の修正** 全件の取得し直しで、次の2つの不具合が分かったため直した。(1) `*_rendered` の列（WordPressが作ったHTML・説明）の変化を、変更として履歴に記録していた。記事を編集しなくても、テーマやプラグインで変わるため、D-05-07 のとおり差分の判定と履歴に使わないようにした。(2) MySQLのJSON型はキーの順序を並べ替えて保存するため、メディアの `sizes` が毎回変更と判定されていた。キーの順序をそろえて比べるようにした。直した後の全件の取得し直しでは、変更は0件だった。ローカルの開発用DBには、直す前に記録された履歴（投稿・固定ページの `content_rendered`・`meta_description_rendered`、メディアの `sizes`）が残っている（XServerのDBにはない）。

### 項目24：AIのAPI実行（2026-09-26）

**D-24-01 呼び出し方** OpenAI の Responses API（`POST /v1/responses`）を、LaravelのHTTPクライアントで呼ぶ（`app/Clients/OpenAi`。ライブラリは加えない）。1回の指示文を送るだけで会話は続けないため、OpenAI側に保存させない（`store: false`）。APIキーは全ブログ共通で .env の `OPENAI_API_KEY` に置く。

**D-24-02 切り替え** 実行方式（手動／API）は、AI実行の画面で実行ごとに選ぶ（初期値は `config/blogos.php` の `ai.methods`。当面は全モードで手動）。API実行では、モデルと推論の深さも選ぶ（初期値は `ai.api.defaults`：全モード gpt-6-sol、SEO分析・構成作成は low、品質診断・記事改修・新規記事作成は medium）。選べるのは gpt-6-luna・gpt-6-sol・gpt-6-astra と、推論の深さ none・low・medium・high（費用がかさむ xhigh・max は選べない。gpt-6-astra は none に対応していない）。

**D-24-03 費用** 料金（1Mトークンあたりの米ドル。2026-09-26 に公式の料金表で確認）は設定に持ち、実行ごとに費用の目安を記録する（キャッシュが効いた入力は安い単価、推論のトークンは出力の単価）。実行の前に「今月（日本時間の月初から）の費用の目安＋今回の最大の費用（入力は文字数をトークン数とみなし、出力は上限いっぱい）」が月の上限（`BLOGOS_AI_MONTHLY_BUDGET_USD`、初期値 $10）を超えるなら実行しない。1回の出力（推論を含む）の上限は 48,000 トークン。OpenAI側の上限・通知とは別に、BlogOSでも確かめる。

**D-24-04 失敗と実行し直し** 料金がかかるため、Jobは自動で再実行しない。失敗（通信エラー・HTTPエラー・出力の上限での打ち切り・取り込めない出力）は実行記録に残し、課金された分の費用も記録する。人が画面から「同じ指示文でもう一度実行」すると、新しい実行記録を作る（前の記録の費用は残す）。同じJobが2回動いても、APIは1回だけ呼ぶ（実行記録の `started_at` で印を付ける）。Queueの `retry_after` は、最も長いJob（1800秒）より長い1900秒にした（短いと、処理中のJobを別の `queue:work` がもう一度取り出すため）。

**D-24-05 接続の確認** `php artisan ai:check` で、短い指示を1回だけ送り、APIキーが使えるか確かめる（gpt-6-luna、費用は $0.001 未満、実行記録には残さない）。テストでは OpenAI API を呼ばない（`phpunit.xml` でAPIキーを空にし、`Http::fake` を使う）。

**D-24-06 実データでの確認** 利用者が OpenAI API を契約し、手動実行（ChatGPT Free・GPT-5.6 Luna・Instant）で品質診断した2記事を、API実行で比べた。記事1：手動 42.0点・gpt-6-sol medium 36.3点・gpt-6-luna medium 38.6点。記事2：手動 61.3点・sol 56.3点・luna 61.3点。1回の費用の目安は sol 約$0.07、luna 約$0.004（入力は文字数の約0.6倍のトークン、出力は約4,000〜5,000トークン、1回35秒前後）。

### 項目25：まとめて実行と、条件による自動の再評価（2026-09-26）

**D-25-01 標準のモデル** 当面は gpt-6-luna（推論の深さ medium）を全ての実行モードの標準にする（D-24-06 の比較で、普段使う ChatGPT と同じ系統で、点数も近く、費用は sol の約1/17のため）。gpt-6-sol は、実行ごとに選べるようにしておき、luna の評価は良いのにアクセス・収益が伸びない記事の見直しや、記事の作成・改修で人の修正の量が多い場合に使う。品質診断の点数の推移を比べるときは、同じモデルの評価どうしで比べる。

**D-25-02 自動実行の範囲（D-07-02 の変更）** D-07-02（人が画面で実行したときだけ動かす）を、次のとおり変える。(1) まとめて実行：人が画面から、品質診断・記事改修を、選んだ記事に1記事ずつAPI実行する（人の操作がきっかけのため、D-07-02 の範囲内）。(2) 条件による自動の再評価：ブログのAIの設定で有効にした場合だけ、毎日（日本時間6:00）、再評価の条件に当てはまる記事を品質診断する。自動で行うのは品質診断（段階0）だけで、記事の改修（段階1）と反映は自動で行わない（D-07-01 は変えない）。自動の再評価は初期値を無効にし、有効・無効と、使うモデル・推論の深さ（初期値 gpt-6-luna・medium）をブログごとに画面で設定する。1記事ずつの実行（手動・API）は、これまでどおり使える。

**D-25-03 まとめて実行の動き** 対象は公開中の投稿・固定ページ。品質診断は「全て」「未評価」「再評価の条件に当てはまる」「最新の評価の点数が基準に満たない」から、記事改修は「点数が基準に満たない」（初期値 90点未満、改修範囲は初期値 軽微）から選ぶ。記事ごとにJobを登録し、Queueの処理で1記事ずつ実行する（記事ごとにAI実行記録を作る）。待機中・実行中の記事は、別のまとめて実行の対象にしない。APIキーがない・月の費用の上限を超える場合は、残りの記事を実行せずに止める（`stopped`）。人が取り消すと、待機中の記事は実行しない（実行中の記事は最後まで実行する）。費用の目安は、同じ実行モード・モデルの過去のAPI実行の平均から計算して、実行前に表示する。

**D-25-04 再評価の条件** 記事が変わっていなければ、再評価しても点数の違いはAIのぶれだけになるため、毎週などの一律の再評価はしない。記事ごとの最新の評価（編集案の評価を除く。人の評価を含む）と比べ、次のいずれかに当てはまる記事を再評価する（複数に当てはまる場合は先の理由を記録し、この順に優先する）。未評価 / 1. 評価した後に記事が更新された（`wordpress_modified_gmt`） / 2. 品質基準（共通基準・ブログ別の定義）または品質診断のテンプレートのバージョンが変わった / 3. この記事へのリンクの数が、評価した時点（`article_evaluations.inbound_link_count`）から変わった（孤立記事・導線の判定が変わるため。この列を記録していない以前の評価は比べない） / 4. アクセスが落ちた：直近28日（Googleの数値が確定しない直近3日を除く）の Search Console のクリック数または GA4 の表示回数が、その前の28日より30%以上減った（前の期間がクリック20・表示50未満の記事は、ぶれが大きいため見ない。前回の評価から28日以上たった記事だけ） / 5. 前回の評価から90日が過ぎた。自動の再評価は1日にブログごと30件まで（超えた分は翌日以降）。数値は `config/blogos.php` の `ai.auto_reevaluation` で変えられる。AdSense の記事ごとの収益は取得できないため（D-21-09）、収益は条件に含めない。

**D-25-05 コマンド** `php artisan ai:auto-reevaluate`（毎日のcron。`--dry-run` で対象の記事と理由を表示するだけ）。実データ（si-note）では、公開中の記事のうち179件が未評価として対象になった（評価済みの2記事を除く）。

### 項目26：診断の後の編集案の作成（2026-09-26）

**D-26-01 流れ** 「評価 → 人が改修を実行 → 人が編集案を確認」を、「評価 → 自動で編集案を作成 → 人が編集案を確認」にする。品質診断のまとめて実行（手動・自動の再評価）で、全ての記事の診断が終わったら、基準に満たない記事（その診断の点数が基準未満、または必須条件を満たさない）の記事改修を、続けてまとめて実行する（`ai_batches.parent_batch_id` で元の品質診断にひも付け、対象は `after_diagnosis`）。1記事ずつの品質診断では行わない（結果を見て人が改修を実行する）。

**D-26-02 D-07-01 との関係** 編集案の作成は段階1のため、AIが行ってよい範囲のまま。自動の再評価の後の編集案の作成は、人の操作をきっかけにしない段階1になるため、ブログのAIの設定で有効・無効を切り替えられるようにした（`blog_ai_settings.auto_revision_enabled`。自動の再評価そのものが初期値で無効のため、こちらの初期値は有効）。WordPressへの反映（段階2）は、これまでどおり人が編集案を確認して行う。

**D-26-03 人の作業を上書きしない** まとめて実行の記事改修（診断の後の作成を含む）では、作業中の編集案がある記事は改修しない（「実行しない」として理由を記録する）。1記事ずつの改修では、これまでどおり作業中の編集案を改修する（D-22-06。人が画面で選んで実行するため）。

**D-26-04 設定** 基準の初期値は受け入れの目安の点数（90点。D-07-03）、改修範囲は軽微、モデル・推論の深さは記事改修の標準（gpt-6-luna・medium）。手動のまとめて実行では、実行ごとに画面で変えられる（初期値で有効）。自動の再評価では、基準と改修範囲は初期値のまま、モデル・推論の深さをブログのAIの設定で変えられる。費用の上限・取り消しで品質診断が途中で止まった場合は、編集案を作らない。

### 項目27：改修後の診断・改修範囲の自動判別・管理情報の案（2026-09-26）

**D-27-01 改修の後の編集案の品質診断** まとめて実行の記事改修（診断の後の作成を含む）では、改修でできた編集案を続けて品質診断し、改修前（記事の最新の評価）と改修後（編集案の評価）の点数を `ai_batch_items.score_before`・`score_after` に記録する。モデルは、元の品質診断のまとめて実行と同じもの（なければ品質診断の標準）を使い、点数を同じ条件で比べられるようにする。まとめて実行の結果の画面と、編集案の画面（記事の最新の評価と、編集案の評価）で確認できる。編集案の診断から、さらに改修が続くことはない。1記事ずつの改修では、これまでどおり人が編集案の画面から品質診断を実行する。

**D-27-02 改修範囲の自動判別（D-06-01 の補足）** まとめて実行の記事改修と、自動の再評価の後の改修で、改修範囲に「点数で自動判別」を選べるようにし、初期値にした。改修前の点数が70点以上なら軽微な改善、40点以上なら構成の見直し、それ未満なら全面改修にする（点数がなければ軽微な改善。基準は `config/blogos.php` の `ai.revision_scope_by_score`）。D-06-01 の「全面改修は人が指定した場合だけ」は、人がこの自動判別を選んだこと（手動のまとめて実行の画面、または自動の再評価のAIの設定）を人の指定とみなす。判別した改修範囲は、記事ごとに `ai_batch_items.revision_scope` と AI実行記録に残す。自動の再評価の後の改修範囲は、ブログのAIの設定（`blog_ai_settings.auto_revision_scope`）で自動判別・軽微・構成の見直し・全面改修から選ぶ。

**D-27-03 記事の管理情報の案** 記事種類・細分類・キーワード・検索意図が登録されていないと、採点が全ての項目を対象にし（記事種類ごとの対象外が効かない）、改修でキーワードがずれるおそれがあるため、AIで案を作り、人が確認して登録する仕組みを作った。実行モード「管理情報の案」（テンプレート `management_suggestion.md` 1.0.0、標準 gpt-6-luna・medium）で、記事の内容・タイトル・Search Console の検索クエリから案を作り、`article_management_suggestions` に確認待ちとして保存する（段階0。記事の管理情報は変えない）。記事種類・細分類は、ブログ別の定義にない値を空にする。同じ記事の新しい案を作ると、前の確認待ちの案は「新しい案に置き換え」にする。「管理情報の案の確認」の画面で、人が値を直してから、チェックした案をまとめて登録・不採用にする。登録は管理情報の履歴に、変更元 `ai`・登録した人として記録する。まとめて実行の対象は「管理情報（記事種類とメインキーワード）が未登録の記事」または「全て」で、確認待ちの案がある記事は除く。1記事ずつも記事の画面から作れる。実データ（si-note）では、指示文は約22,500文字で、公開中の181件すべてが未登録だった。

### 項目28：編集案のプレビュー（2026-09-27）

**D-28-01 方式** 編集案の画面から、左に変更前（WordPressの今の記事）、右に編集案を、実際のサイトの表示で並べて比べる。正確な表示のため、WordPress自身に表示させる（案2）。WordPress側の拡張（`Theme-SI-Original` の `inc/blogos-connector.php`）に、保存せずに渡した内容で表示する仕組みを加えた（WORDPRESS_API 25-2）。表示するのは保存済みの編集案。

**D-28-02 アクセス解析・広告・表示回数に数えない** BlogOSのサーバーが表示用のURLのページを取得し、スクリプト・noscript・イベント属性を取り除いてから、BlogOSの画面の枠（iframe）で表示する。枠の中身には `Content-Security-Policy: script-src 'none'` を付け、スクリプトを一切動かさない。そのため、GA4・AdSense には数えられない（AdSense の広告を不正に表示させることにもならない）。WordPress側でも、プレビューの表示はテーマの表示回数（`post_views_count`）に数えない。スクリプトで動く部分（広告・コードのコピーのボタン・メニューの開閉など）は表示されない。

**D-28-03 表示の形** PC表示（幅1200px）とスマホ表示（幅390px。スマートフォンのブラウザの名乗りで取得し、テーマのスマホ用の表示にする）を切り替えられる。枠の幅に合わせて縮めて表示し、左右のスクロールを連動できる。リンクは新しいタブで開く。新規記事は、同じ種類の最新の公開済みの記事を土台にし、タイトル・本文・抜粋だけを差し替える（カテゴリ・日付・関連記事などは土台の記事のもの）。WordPressが返した表示用のURLが、ブログのURLでなければ取得しない。

### 項目29：日本語のスラッグ（2026-09-27）

**D-29-01 保存と表示** WordPress は日本語のスラッグを URL 用に符号化した形（例：`%e3%83%87%e3%83%bc%e3%82%bf`）で保存し、API でもその形で返す。BlogOS は、WordPress 由来のテーブル（`posts`・`pages`・`tags` 等の `slug`）にはその形のまま保存し（同期の差分の判定のため）、画面では読める形に戻して表示する（`App\Support\Slug::display`）。DB確認の画面は、保存した値と、読める形を並べて表示する。si-note では、投稿169件・タグ2件・メディア1件が日本語のスラッグだった。

**D-29-02 編集と反映** 編集案には読める形で写す（WordPress に送ると、WordPress が符号化する）。反映の前の比較・カテゴリとタグの更新の比較・競合の判定・完全削除の確認の入力では、符号化した形と読める形、大文字と小文字の違いを同じとみなす（`Slug::same`）。以前に作った編集案（符号化した形のまま）も、画面では読める形で表示し、変えていなければ反映で送らない。

### 項目30：収益用の教材（2026-09-27）

**D-30-01 教材の登録** 記事で紹介する教材（書籍・Udemy・スクール）を `materials` に登録する。書籍は Amazon と楽天の2つのアフィリエイトのリンク（もしも経由）、Udemy・スクールは1つのリンクを持つ。リンクは URL か、`<a href>` を含むHTML（もしもの管理画面でコピーしたもの）で入力でき、URL にして保存する。記事との照合のため、同じ教材の別のリンク（`extra_urls`）と、アフィリエイトではない商品ページ（`product_url`）も持てる。書籍の ASIN・ISBN は、Amazon のリンクから分かれば補う。状態は「使う／使わない」。記事で使っている教材は削除せず「使わない」にする。

**D-30-02 記事との照合** 記事の本文にある、アフィリエイトのサービスを経由するリンク（もしも・楽天アフィリエイト・Udemy の紹介リンク・Amazon の紹介リンク）を読み取り、登録した教材と照合して、記事で使っている教材を `article_materials` に記録する（`App\Services\Materials\MaterialLinkService`）。リンクは、書き方やもしもの `a_id` が違っても同じ教材と分かる識別子で比べる（もしもの遷移先の ASIN・楽天ブックスの番号・Udemy の紹介リンクの記号など。`App\Support\AffiliateLink::key`）。照合は、同期で本文を取り込むたびと、教材を登録・更新したときに行う。本文からなくなった教材の記録は消す（人が登録した記録は残す）。別のサイトへの普通のリンク（si-note の snnsk.com の Excel 講座など）は、教材として扱わない（人の判断）。

**D-30-03 既存のリンクからの登録** 既存の記事にあるリンクを教材ごとにまとめて表示し、人が名前・種類を確かめて登録できる。同じ見出しの下にある「Amazonで見る」「楽天で見る」は同じ書籍、別の記事で組み合わせて使われているリンクも同じ教材とみなす（リンクの文字が書名のときは書名ごと）。Udemy は講座名が同じならリンクが違っても同じ講座とみなす。実データ（si-note）では、書籍19件・Udemy6件・スクール4件（DMM WEBCAMP 2件・AWSの問題集2件）が見つかった。JavaScript の記事のほぼ全部（約125件）が、同じ書籍2冊・Udemy 2講座・スクール1件を紹介していた。

**D-30-04 選ぶための情報と、AIによる調査** 記事に合う教材を選ぶため、教材に、カテゴリ（ブログのカテゴリ）・分野の語句・対象のバージョン・対象のレベル・向いている場面（品質基準の「紹介する場面」を値にしたもの）・学べる内容・向いている人／向いていない人・メリット・注意点・出版日（Udemy は最終更新日）・版・前の版（`previous_material_id`）を持たせる。これらは、実行モード「教材の調査」（`material_research.md`）でAIが調べ、案（`material_suggestions`）として保存し、人が写す項目を選んで教材に写す。調べ方は3つを組み合わせる：(A) Web検索（OpenAI の `web_search`。API実行だけ。1回の実行で8回まで。検索の回数と料金を実行記録に残し、月の費用の上限の判定に含める）、(B) 販売サイト等のページの文章の貼り付け（サイトごとに形式が違っても、決まった項目に当てはめさせ、ページにない項目は空にさせる）、(C) 書籍は楽天ブックス書籍検索API（楽天ウェブサービスのアプリID `RAKUTEN_APPLICATION_ID`。未設定なら使わない）の結果を指示文に入れる。価格は保存しない（スクールだけ、費用の目安と確認した日を持つ）。

**D-30-05 新しい教材の候補** 実行モード「教材の候補探し」（`material_discovery.md`）で、カテゴリを指定し、そのカテゴリの記事のタイトルを渡して、登録済みでない教材の候補をAIに探させる。候補はアフィリエイトのリンクを含めず、人が内容を確かめ、もしも・Udemy でリンクを作って貼り付けてから登録する。登録済みの教材・確認待ちの候補と、ISBN・商品ページ・名前が同じものは除く。

**D-30-06 二段階の判別** 記事に合う教材は、一段目でBlogOSが機械的に候補を絞り（記事のカテゴリとその親のカテゴリ、タイトル・キーワード・カテゴリ名・タグと、教材のカテゴリ・分野の語句の一致。親ロードマップは紹介しない、子ロードマップは体系的に学べる教材だけ。候補は8件まで。`App\Services\Materials\MaterialMatcher`）、二段目でAIが品質基準に照らして使うか・どれを使うかを判断する（0件も正しい答えとする）。古い教材も一段目では除かない（旧バージョンの記事があるため）。記事の対象のバージョンを管理情報（`article_managements.target_versions`）に持たせ、管理情報の案（1.1.0）でAIに案を出させる。記事改修・新規記事作成への組み込み（本文には目印だけを書かせ、BlogOSがリンクのHTMLに置き換える）は、HTMLのルール（`html-rules.md`）と一緒に行う。

**D-30-07 見直しと定期チェック** 教材を「使わない」にした・新しい版を登録した・教材の情報を更新した・記事が更新された（WordPress の更新日時）場合に、その教材を使う記事を「見直しが必要」にする（`article_materials.reviewed_at` との比較）。ただし、情報が空だった教材に初めて情報を入れた場合は対象にしない（既存のリンクを登録して調べたときに、全記事が対象になるのを避けるため）。実行モード「記事の教材の見直し」（`material_review.md`）で、AIが今の教材ごとに「このまま／差し替え／外す」と追加の候補を判断し（`article_material_reviews`）、人が確認する。記事は自動では変えず、差し替えは記事の改修で行う。まとめて実行でも見直せる。教材の定期チェックは、AIの設定で有効にしたブログだけ、毎日6:30に、前回の調査から6か月が過ぎた教材を1日5件まで調べ直す（Web検索を使うAPI実行）。新しい版・後継・同じ著者の関連の教材は候補に、情報の変化は情報の案になる。

**D-30-08 品質基準の変更（共通基準・si-note 1.1.0）** 人の判断により、「読んでいない書籍を推奨しない」「内容を確認せずに紹介しない」「実際に内容を確認していないものを推奨しない」を外し、記事の内容に沿った教材として、公開されている情報に基づいて紹介し、読んだ・受講したかのような書き方をしないことにした（`common/writing.md` 7-3、`si-note/tech-content.md` 5-2・5-3）。広告であることの表示（ステマ規制）を追加した（`common/writing.md` 7-4。文言・位置は `html-rules.md` で決める）。紹介する教材の選び方と数の上限（集客記事・子ロードマップは書籍2件・Udemy2件・スクール1件まで）を追加した（`si-note/tech-content.md` 5-6）。採点の項目は変えていないが、基準の内容を変えたためマイナーバージョンを上げた。バージョンが変わったため、評価済みの記事は「再評価の条件（2）」に当てはまる。

**D-30-09 書籍の商品ページ（2026-09-28）** 書籍は、Amazon と楽天ブックスで商品ページのURLが違うため、`amazon_product_url`・`rakuten_product_url` を別に持つ（人の指摘）。空なら、アフィリエイトのリンク（もしも）の遷移先から補う（`https://www.amazon.co.jp/dp/ASIN`・`https://books.rakuten.co.jp/rb/番号/` の形にそろえる）。`product_url` は、Udemy の講座ページ・スクールの公式サイト・書籍の出版社のページに使う。Udemy・スクールも、リンクに遷移先が入っていれば（もしも経由など）、空の `product_url` に補う。AIの案で `product_url` に Amazon・楽天のページが入っていた場合は、それぞれの欄に分ける。教材の調査・候補探しのテンプレートを 1.1.0 にした（出力に2つの欄を追加）。登録済みの教材は、Migration で補った（si-note：書籍の Amazon 9件・楽天19件、スクール2件）。

**D-30-10 AIの回答のJSONの書き間違いと、取り込み直し（2026-09-28）** 教材の調査の実行で、AIの回答のJSONに、閉じかっこの直前の余分な「,」があり、取り込めなかった。JSONを読み取るすべての実行モード（品質診断・管理情報の案・教材）で、この書き間違いだけは直してから読み取る（文字列の中は変えない）。あわせて、回答はあるが取り込めずに失敗したAPI実行は、AI実行記録の画面から、保存済みの回答で取り込み直せるようにした（APIは呼ばないため、料金はかからない）。

### 項目31：API実行の費用の計算（2026-09-28）

**D-31-01 料金表の直し** BlogOS の費用の目安（$3.23）が、実際に使った額（$10 − 残高 $6.43 = $3.57）より約1割少なかった。OpenAI の Usage の画面と比べると、入力のトークン数は一致しており（差は記録していない接続の確認 `ai:check` の1回・16トークンだけ）、原因は料金表の漏れだった。公式のモデルのページ（2026-09-28 に確認）のとおり、次を加えた。(1) キャッシュの書き込み：キャッシュされていない入力は、OpenAI が自動でキャッシュに書き込み、入力の1.25倍の料金になる（luna $0.125・sol $2.50・astra $12.50 / 1M。1,024トークン未満の入力はキャッシュされないため入力の料金）。(2) 長い入力：1回の入力が272,000トークンを超えたら、その1回すべてを 入力・キャッシュは2倍、出力は1.5倍。(3) Web検索は、検索の動作（`action.type` が `search`）だけを数える（ページを開く・ページの中を探す動作は料金の対象ではない。OpenAI の画面では6回、BlogOS は8回と数えていた）。直した計算では $3.5951 で、実際の額との差は約 $0.025（うち $0.02 は以前の数え方の Web検索）。

**D-31-02 記録の計算し直し** `ai:recalculate-costs`（`--dry-run` あり）で、保存済みのトークン数から費用の目安を計算し直した（730件）。料金表の設定の誤りを直したときだけ使い、OpenAI が料金を変えた場合は使わない（過去の実行は、その時の料金で請求されているため）。

**D-31-03 料金表の毎日の照合** 料金の変更に追いつくため、料金表を DB（`ai_prices`）に移し（`config/blogos.php` の値は初期値と、料金表にないモデルの値）、毎日4:30に `ai:check-prices` で OpenAI の公式のページと照合する。モデルごとのページ（入力・キャッシュ済みの入力・キャッシュの書き込み・出力、長い入力の規則）と、料金ページ（Web検索 `Web search (all models) … / 1k calls`）を、AIを使わずに読んで料金の部分だけを取り出す（料金はかからない）。API実行のたびには照合しない（ページが大きく実行が遅くなり、OpenAI のサイトが読めないと BlogOS の実行まで止まるため。料金の変更は頻繁ではない）。値上がりは自動で反映する（目安が高めになる方向で、上限の判定は安全側のため）。値下がりは、ページの読み違いで使いすぎを止められなくなるのを避けるため、AIの設定の画面で人が確認して反映する（反映しないこともできる。公式のページが元の値に戻ったら、確認待ちは不要にする）。ページから料金を読み取れない・ありえない値のときは、今の料金表のままにする。変更は `ai_price_changes`、照合の結果は `ai_price_checks` に記録し、確認待ち・読み取れなかった料金・直近7日の変更を、トップページとAIの設定の画面で知らせる。

**D-31-04 OpenAI の残高の見込み（D-07-08 の見直し）** OpenAI の API は前払い（チャージした残高から引かれる）で、課金は月に数回のことも、数か月に1回のこともあるため、月の支出の上限（固定の $10）だけでは判断できない。BlogOS から OpenAI の残高は取得できず（管理者用のキーが必要なため使わない）、課金もできないため、人が OpenAI の画面で見た残高（Credit balance）と、課金した額を「AIの費用と残高」の画面で登録する（`ai_credit_entries`）。残高の見込み ＝ 最後に登録した残高 ＋ その後の課金 − その後のAPI実行の費用の目安。見込みが $3（`BLOGOS_AI_CREDIT_WARNING_USD`）以下で画面（トップページ・AIの各画面）で知らせ、「見込み − 今回の最大の費用」が残しておく額 $0.50（`BLOGOS_AI_CREDIT_RESERVE_USD`。見込みのずれへの備え）を下回るならAPI実行をしない（残しておく額の2倍以下で「止まっている、またはまもなく止まる」と知らせる）。残高が未登録なら止めずに、登録を促す。実際の残高を登録すると、その時点の見込みとの差を記録し、見込みは実際の残高から計算し直す（ずれは自動で直る）。30日以上登録していなければ、照合を促す。ずれが大きいときに原因を調べられるよう、OpenAI の Usage の画面と同じ単位（UTC の日付・モデルごとの Requests・Input tokens・Output tokens・Web Searches）で BlogOS の記録を表示する。月の支出の上限（`BLOGOS_AI_MONTHLY_BUDGET_USD`）は任意にし、空なら設けない。

### 項目32：記事で使う画像（2026-09-28）

**D-32-01 種類ごとの作り方** 品質基準で多くの記事に図解・画面例が必要なため、記事の改修・作成に組み込む前に、画像の仕組みを用意した（`images`）。種類ごとに作り方を分ける。図解（フロー図・構造図など）は、文章のモデル（gpt-6-luna）が SVG で作る。イラスト（例え話・概念のイメージ）とアイキャッチは、画像モデル（`gpt-image-2.5-flare`。Images API）で作るか、ChatGPT 等で作ってアップロードする。スクリーンショット・実行結果は、人が撮ってアップロードする（AI が作った画面は事実ではないため。実行結果は、今のまま記事の中の文字（コードの枠）で載せる）。実行モード「図の作成」（`image_design.md`）では、AI が図ごとに SVG とイラストのどちらが合うかを理由と一緒に選ぶ（正確さが必要な図は SVG。画像モデルは日本語や矢印の正確さが苦手なため、イラストは文字を入れず、説明はキャプションに書く）。イラストを選び、API実行だった場合は、続けて画像モデルで作る。人が比べたいときは「もう一方の形式でも作る」で別の形式の画像を作り、並べて選ぶ（常に両方は作らない。料金のため）。

**D-32-02 SVG の図の PNG への変換** WordPress は標準では SVG を受け付けず、XServer には日本語のフォントがない可能性が高いため、BlogOS の画面（利用者のブラウザ）で SVG を描いて、2倍の大きさの PNG にして保存する（ボタン1つ。PC の日本語のフォントで描くため文字化けしない）。SVG の元のデータは残し、画面で直せる（直したら PNG にし直す）。AI が作った・人が直した SVG は、保存するときにスクリプト・イベント属性・外部への参照・HTML の埋め込み・DOCTYPE を取り除く（`App\Support\SvgSanitizer`）。画面では SVG を `<img>` で表示する（スクリプトは動かない）。

**D-32-03 アイキャッチ** 今の si-note は、技術ごとの共通の画像（4枚）を使い回しているため、それを続ける。カテゴリごとのアイキャッチ（WordPress のメディア）を登録し（`category_eyecatches`）、子のカテゴリに設定がなければ親のカテゴリの設定を使う。登録の目安として、そのカテゴリの記事でいちばん多く使われているアイキャッチを表示する。画像を作るのは、新しい技術のカテゴリを始めるときだけ。

**D-32-04 確認と WordPress への登録** 画像は案として保存し、ファイル・alt・ファイル名（英小文字・数字・ハイフン）がそろってから人が確認済みにする（品質基準の「alt は必須、ファイル名は内容が分かる名前」）。確認済みの画像を、反映の共通の仕組みで WordPress のメディアに登録する（`POST /wp/v2/media`、ファイルと一緒にタイトル・alt・キャプションを送り、反映記録に残す）。si-note で、テスト画像を登録してすぐ完全削除し、確認した（メディア #3373）。アップロードする画像は、ブラウザで横幅1600pxまでに縮めてから送る。記事の本文への組み込み（目印の置き換え、スクリーンショットの依頼の一覧、新しい記事のアイキャッチの設定）は、HTMLのルールと一緒に行う。

**D-32-05 画像の費用** 画像モデルは、文章の入力 $5・画像の入力 $8・画像の出力 $30 / 1Mトークン（標準の処理）。応答のトークン数から費用を計算し、AI実行記録（`image_generation`）に残して、残高の見込みに含める。実行前は、品質ごとの出力のトークン数の見積もり（low 1,500・medium 4,000・high 12,000）で最大の費用を見て、残高の見込みで判定する。画像モデルの料金も、毎日の料金表の照合の対象にした（料金ページの表）。画像モデルは、OpenAI の組織の本人確認（Organization Verification）が必要な場合があり、そのときは対処を添えて失敗として記録する。新しい契約は要らない（同じ API キー・残高を使う）。

### 項目33：HTMLのルール（2026-09-29）

**D-33-01 基にする記事** si-note の HTML のルール（`resources/quality/blogs/si-note/html-rules.md`）は、投稿（WordPress ID：24）の HTML を基に作る。部品は、今のテーマ（Theme-SI-Note・Theme-SI-Original）の class を使う。確定するまでは、AI実行の指示文に含めない。確定時に、共通基準と si-note のバージョンを 1.2.0 に上げる。

**D-33-02 広告と教材の紹介の位置** 広告（WP QUADS に登録した AdSense の広告ユニット）は、id=1（rectangle-top）を導入文の後、id=2（rectangle-middle）と id=4（rectangle-middle2。長い記事だけ）を本文の H2 の間、id=3（rectangle-bottom）をまとめの前に置く。これに合わせ、共通基準の「導入文の直後・まとめの直前に広告を置かない」を「ファーストビュー（タイトルから導入文まで）に置かない。位置と数はブログ別の定義で定める」に改めた（D-06-06 の変更）。教材の紹介（収益導線・CTA）は FAQ の前に移し、「教材の紹介 → FAQ → 広告 → まとめ」とする（D-14-09 の変更。CTA と広告を連続させないため）。

**D-33-03 BlogOS が入れるもの** 広告のショートコード、広告を含むことの表示（ステマ規制。教材がある記事だけ、記事の冒頭に「本記事にはプロモーション（アフィリエイト広告）を含みます。」）、教材の枠の見出しの「PR」の印は、AI に書かせず BlogOS が入れる（書き忘れ・位置の誤り・文言の揺れを防ぐため）。法律上の規制の対象は広告主だが、ASP の規約で表示が求められる。AdSense の広告は Google が「広告」と表示するため対象にしない。

**D-33-04 既存の記事の書き方の置き換え** 次のものは、記事を改修するときに置き換える。テーマの教材のショートコード（`[aws_book_box_beginner]` など。同じ紹介文を全記事で使う形）は、記事ごとの教材の目印にする（すべて置き換えたら `parts/` を削除する）。FAQ の構造化データ（JSON-LD）は削除する（2023年から、FAQ のリッチリザルトは政府・医療のサイトだけのため）。`/wp-content/img/` の画像は、WordPress のメディアライブラリの画像にする（どの記事で使うかは BlogOS で管理する）。コードの言語の class は、記事の内容の言語にする。

**D-33-05 記事種類ごとの教材の数** 今はどの記事も教材5件（書籍2・Udemy2・スクール1）だが、記事種類の役割に合わせて上限を分ける（`tech-content.md` 5-6）。子ロードマップは、読者が学習計画を立てる場面で、教材を紹介する中心の場所として今のまま（5件まで）。集客記事は、1つの疑問を解決しに来た読者に5件は重く、「押し売りしない」「本文より目立たせない」と衝突しやすいため、合計3件まで（各種類1件、0件も可、スクールは独学でつまずきやすい内容だけ）。親ロードマップは、詳しい教材は子ロードマップにあるため合計3件まで（ステップごとに置く今の設計は続け、子と同じ教材は避ける）。収益記事は、比較の対象の種類ごとに3〜5件（収益記事のルールを作るときに見直す）。あわせて、親・子ロードマップの CTA の記載が今の運用と逆になっていたのを直した（`article-types.md` 2-4・3-4）。

**D-33-06 記事へのリンクの目印** ロードマップ・次に読む記事・関連記事のリンクは、AI に HTML を書かせず、記事の目印（`[[記事:ID]]`）を書かせ、BlogOS が今の URL とタイトルに置き換える。固定ページ 2063 に、投稿24の古いタイトルのリンクが残っていたため。

**D-33-07 残りの部品** 注意の枠は子テーマに `caution-box` を作る（親テーマの STINGER の装飾は使わない）。実行結果は `language-text` のコードの枠にし、画面の結果はスクリーンショットにする。画像のキャプションは使わない。教材の枠に、書籍・Udemy は注意点、スクールは費用・期間の目安と向いていない人を書く（品質基準のデメリットの記載に合わせる）。表に class を付けない。id=4 の広告は、本文 6,000字以上かつ `h2` 6個以上の記事だけ。テーマに見た目・機能がないもの（`pr-note`・`pr-label`・`caution-box`・Prism の autoloader・`summary-box`・`reason`・表の横スクロール）は、`html-rules.md` 6章にまとめ、BlogOS の作業の後のテーマの作業で行う。

**D-33-08 問題集とアフィリエイトのプログラムの状態** 資格の記事で紹介しているオンライン問題集（zero to one。もしも経由）を管理するため、教材の種類に「問題集・オンライン教材」（`question_bank`）を加えた（費用・利用期間を載せる。資格の記事だけ1件まで。`tech-content.md` 5-7、枠は既存の `cert-box`）。あわせて、ASP のプログラム（提携先の広告。もしもは広告 p_id ごと、Udemy・Amazon・楽天はサービスごと）と状態（提携中・申請中・否認・提携終了・未確認）を管理する（`affiliate_programs`）。提携中でないプログラムのリンクしかない教材は、AI の教材の候補から外し、記事で使っている場合は差し替えを促す（画面「アフィリエイトのプログラム」に、提携中でないプログラムのリンクがある記事を出す）。記事のリンクから自動で登録したプログラムは「未確認」とし、今の記事を止めないよう使える扱いにして、人に確認を促す。プログラムに教材の種類を登録すると、リンクから教材を登録するときの種類に使う（スクールとして登録した問題集を直す機能もある）。あわせて、ロードマップの一段目の候補を「体系的に学べる教材」にした（親ロードマップも紹介するため。D-33-05）。

**D-33-09 リンクの定期確認（提携終了の検知）** DMM WEBCAMP（もしも p_id 1000。121記事で使用）の提携が、気づかないうちに終わっていた（リンク先が「お探しのページは見つかりませんでした」）。ASP には提携の状態を取得できる公開の API がないため、BlogOS が週1回（月曜 06:45。`affiliate:check-links`、画面の「リンクを今すぐ確かめる」）、記事で使っているプログラム（提携中・未確認）ごとにリンクを1本だけ開いて確かめる。もしもは、提携が終わった広告のリンクを `/af/www/expiration` へ転送するため、最初の転送先だけを見る（広告主のサイトは開かない。実際の DMM のリンクで確認した）。それ以外（Udemy など）は転送をたどり、行き先がエラーなら疑いとする。クリックの数への影響を小さくするため、プログラムごとに1本・週1回とする。状態は自動では変えず、「提携終了の疑い」としてトップページと画面で知らせ、人が ASP の管理画面で確かめて直す。DMM WEBCAMP を使っている121記事は、記事の改修のときに差し替える（利用者の判断）。

**D-33-10 確定とバージョン** `html-rules.md` を確定し、共通基準と si-note を 1.2.0 に上げた（2026-09-29。広告・CTA の位置、教材の位置・数、問題集、HTML のルールの追加。採点の項目は変えていない）。バージョンが変わったため、評価済みの記事は「再評価の条件（2）」に当てはまる。記事改修・新規記事作成の AI 実行テンプレートへの組み込みは、次の作業（BlogOS の目印の置き換え・広告のショートコードの挿入を含む）で行う。

### 項目34：記事改修・新規記事作成への組み込み（2026-09-29）

**D-34-01 AIの指示文（記事改修・新規記事作成 1.2.0）** 品質基準に `html-rules.md` を加え、本文はブロックエディターのコメントではなく HTML のルールどおりに書かせる。広告のショートコード・広告を含むことの表示・PR の印・FAQ の構造化データは書かせない（BlogOS が入れる・外す）。指示文に、記事の一覧（目印 `[[記事:WordPress の ID]]` 付き。投稿と固定ページの ID は重ならない）、紹介してよい教材の候補（一段目の絞り込みの結果。提携中でない教材は差し替えを促す）、この記事の画像を入れる。出力に「=== 画像の依頼 ===」（JSON の配列）を加えた。新規記事の画面でカテゴリを選べるようにした（教材の候補とアイキャッチに使う）。

**D-34-02 仕上げ（App\Services\Articles\ArticleHtmlFinisher）** AIの出力を編集案に取り込むとき、BlogOS が本文を仕上げる。(1) 古い書き方を外す（FAQ の構造化データ、テーマの教材のショートコード、広告のショートコード、前の表示・PR の印）。(2) 目印を置き換える：`[[教材:ID]]` は提携中のリンク（書籍は Amazon・楽天のボタン）、`[[記事:ID]]` は公開中ならリンク、公開していなければタイトルだけ（コメントで目印を残し、公開後に仕上げ直すとリンクになる）、`[[画像:ID]]` は WordPress のメディアの `img`。(3) 広告を含むことの表示（教材のリンクがある記事の冒頭）、教材の枠の見出しの PR の印、広告（id=1 は最初の本文の H2 の前、id=2 は本文の中ほど、id=4 は長い記事だけ、id=3 は FAQ の後）を入れる。広告の ID・表示の文言・画像の上限は `config/blogos.php` の `article_html`（品質基準のブログ別の定義ごと）に持つ。仕上げで人に伝えることは編集案（`article_drafts.finish_notes`）に残す。編集案の画面の「目印を置き換え直す」で、いつでも仕上げ直せる（画像を WordPress に登録した後など）。

**D-34-03 画像の依頼** AIが依頼した画像は、編集案に結び付けて画像の登録（案）を作る（`images.article_draft_id`）。上限は図解3枚・イラスト1枚（超えた分は作らず、目印を残して人に消してもらう）。図解は費用が小さいため、続けて API で SVG の図を作る（利用者の判断。APIキーがない・残高が足りない場合は作らず、画像の画面の「図を作る」から作る）。イラストは自動では作らず、人が図と比べたいときに画像の画面で作る（AIの依頼の指示文を残す）。スクリーンショットは、編集案の画面に撮影の依頼として出す。

**D-34-04 目印が残っている編集案は反映しない** 置き換えられていない目印（WordPress に登録していない画像など）が本文にあると、読者に目印の文字が見えるため反映しない（利用者の判断）。使わない場合は、人が本文から目印を削除して反映する。

**D-34-05 まだ行わないこと** 収益記事の比較表から教材の解説へのページ内リンクの `id` の付与（最初の収益記事を作るときに作る）、既存の記事の `/wp-content/img/` の画像のメディアライブラリへの移し替え（改修では `<img>` をそのまま残させる）、公開された記事へのリンクの自動の切り替え（今は人が「目印を置き換え直す」を押す。今後の検討事項 5）。

**D-34-06 画像の目印の結び付け（2026-10-01）** 本番の編集案で、画像の案は作られているのに、本文にその画像の目印がない（どこに置く画像か分からない）ものがあった。記事改修・新規記事作成のテンプレートの「画像の依頼」の例の `key` に説明文（「新規1（本文の [[画像:新規1]] と同じ）」）を書いていたため、AI がそれをそのまま写し、本文の `[[画像:新規1]]` を作った画像の目印に付け替えられなかった。例を `"新規1"` だけにし（テンプレート 1.2.7）、読み取りでは key から「新規N」を取り出す（数字だけも「新規N」にする）。すでにできた編集案は、「目印を置き換え直す」で、保存済みの AI の回答の画像の依頼を読み直し、画像の名前（決まらなければ順番）でこの編集案の画像と結び付け直す。まとめて直すコマンド `drafts:repair-image-markers`（一度だけ使う）。

### 項目35：用語の説明の基準（2026-09-29）

**D-35-01 ITに触れたことがない人を基準にする** 作り直した改修案（投稿24）に、用語の補足が1つもなかった。品質基準に説明の対象の定めがなく（「初心者がつまずく用語」だけ）、AIの判断に任されていたため。利用者の判断により、ITに触れたことがない人が理解できない一番の原因は、聞き覚えのないカタカナの用語・英字の略語が説明なしに出てくることであり、このブログの要とする。si-note の想定読者に「ITに触れたことがない人」を明記して書くときの基準にし、`profile.md` 3-5 用語の説明（対象：カタカナの用語・英字の略語・漢字の専門用語。対象外：日常で使う語。迷ったら説明する。記事ごとに初出で説明する。説明の中に説明していない用語を使わない。1文に用語を詰め込まない）を追加した。`reader.terms` は、説明のない用語が1つでもあれば ○ にしない。`html-rules.md` 3-4 を合わせて書き直した。si-note を 1.3.0、記事改修・新規記事作成のテンプレートを 1.2.1 にした（改修では、改修範囲にかかわらず補足を加えさせる）。

**D-35-02 本文にある用語だけを、日常の言葉で説明する** 作り直した改修案（編集案 #186）で、本文に出てこない用語（属性・関数・メソッドなど6語）の補足が作られ、読者が混乱する状態だった。説明の対象を「その記事の本文に実際に出てくる語」に限り、補足の見出しの表記を本文に合わせ、説明文では専門用語を別の専門用語で言い換えない（日常の言葉で書く）ことを明記した（si-note 1.3.1、テンプレート 1.2.2）。あわせて、BlogOS の仕上げで、本文（その補足の枠の外）に出てこない用語の補足を見つけ、編集案の画面で知らせる（「DOM（ドム）」のような括弧・「・」で区切った語は、どれかが本文にあればよい。自動では消さない：表記の揺れで正しい補足を消さないため）。既存の記事の補足46件では誤って知らせるものはなかった。

**D-35-03 決まった形の部品は BlogOS が枠に直す** 親ロードマップ（固定ページ 2204）の改修案で、AIが FAQ を `faq-box` を使わず見出しと段落で書き、テーマの FAQ のデザインが当たらなかった（テーマの CSS は投稿・固定ページのどちらにも当たる。子ロードマップでは枠を使っていた）。ほかにも、まとめの見出しが枠の外にあり、その間に広告が入る、関連記事が枠の外にある、FAQ の見出しが重複する、などのずれがあった。AIに100%守らせるのは難しいため、仕上げで次を直す：枠のない FAQ（「よくある質問」「FAQ」の見出し・質問の h3・回答）を `faq-box` の形にする、`faq-box` の直前の重複した見出しを外す、枠の外の「まとめ」の見出しを `summary-box` に入れる（枠がなければ作る）、見出しとリストだけの「次に読む記事」「関連記事」を `next-article-box`・`related-box` で囲む。`point-box` がない場合は知らせる（内容は作れないため）。広告の判定で「まとめ：…」の見出しもまとめとして扱う。テンプレートを 1.2.3 にし、省略してはいけない枠を明記した。

**D-35-04 教材の紹介が出なかった理由と対策** 作り直した改修案（投稿24・固定ページ 2204・2063）に、教材の紹介が1つもなかった。組み込みは動いていた（指示文に教材の候補が入っていた）が、登録済みの教材29件のうち28件は情報（学べる内容・向いている場面など）を調べておらず名前とリンクだけだったため、AIが品質基準（公開されている情報に基づき推測で書かない）に従って「内容を確認できない」と紹介を外していた。ロードマップは「体系的に学べる」場面の教材だけを候補にするため、候補がなかった。対策：(1) 情報を調べていない教材（学べる内容がない）は、一段目の候補に入れない。(2) 候補の情報は公開情報から調べて人が確認したものであり、その範囲で紹介文を書くこと、「確認できない」を理由に外さないこと、外すのは品質基準の選び方に当てはまる場合だけであることを指示文に明記した（テンプレート 1.2.4）。(3) 教材の紹介がない編集案は、画面で知らせる。教材の情報は、教材の画面の「AIで調べる」（まとめて調べる）で調べ、案を人が確認して登録する。

### 項目36：タイトル・メタディスクリプションのルール（2026-09-29）

**D-36-01 基準** si-note の公開中の投稿170件は、91%に【JavaScript入門】などの書き出しがあり、タイトルの長さの中央値は56文字（158件が32文字超）、メタディスクリプションの未設定が10件だった。書き出しの固定そのものは Google の評価を下げないが、検索結果で切れずに見える先頭（パソコンで30〜35文字ほど）を使い、検索された語が後ろに押し出され、同じサイトの記事が並ぶと区別しにくい。利用者の判断により、共通基準 `writing.md` 3-1 に「先頭28文字以内にメインキーワードと検索意図、全体40文字程度まで、似たタイトルにしない」、3-2 に「80〜120文字、先頭50文字ほどに要点、すべての記事に設定、重複させない」を追加した（共通基準 1.3.0）。si-note は `article-types.md` 1-2 に記事種類ごとのタイトルの型を追加し、【〈技術〉入門】はタイトルの最後に置き、【初心者向け】は使わず、ロードマップ・収益記事には付けないことにした（si-note 1.4.0）。記事改修・新規記事作成のテンプレートを 1.2.5 にした。

**D-36-02 BlogOS での確認** AIを使わない機械的な確認（`App\Services\Articles\ArticleTitleChecker`。数値は `config/blogos.php` の `title_checks`）：タイトルの文字数、先頭の【…】、先頭28文字の中のメインキーワード（空白で区切った語ごと。最後の【…】にある語はよい）、メタディスクリプションの未設定・文字数・メインキーワード、ほかの公開中の記事との重複。編集案の画面に出すほか、画面「タイトル・メタディスクリプションの改善の候補」（`articles.titles`）で、公開中の記事を Search Console の直近90日の指標とあわせて一覧にし、掲載順位ごとのクリック率の目安との差（取りこぼしのクリック）の多い順に並べる。

**D-36-03 既存の記事の直し方（検討中）** 利用者の案：インデックス未登録の記事は検索結果に出ていないため、タイトルを変えても失うものがなく、まとめて直して様子を見る。ただし「クロール済み - インデックス未登録」は Google が読んだうえで登録を見送った状態で、タイトルだけでは改善しにくく本文を含む改修が必要。記事ごとの登録状態と理由は、Search Console の URL 検査 API（今の読み取りの権限で使える）で取得できるため、今後の検討事項 14 で取り込んでから、未登録の記事をまとめて改修する案を出した。

### 項目37：インデックスの登録状態（2026-09-29）

**D-37-01 取得の方法** 記事ごとのインデックスの登録状態を、Search Console の URL 検査 API（`urlInspection/index:inspect`）で調べる。今の Google 連携の権限（`webmasters.readonly`）で使え、無料（プロパティごとに1日2,000件まで）。毎日 05:30（`google:inspect-index`）と画面の「今すぐ調べる」（Queue）で、まだ調べていない記事・反映で変わった記事・前に調べてから日数が過ぎた記事（登録済み30日・未登録7日）の順に、1日300件まで調べる。記事ごとに最新の状態を `google_index_statuses` に持ち、分類が変わったら前の分類と日時を残す（改修の効果を追うため）。1日の上限・権限のエラーでは止める。

**D-37-02 分類と改修の方針** coverageState（英語で受け取る）から、登録済み・クロール済み - インデックス未登録（本文の質・独自性・内部リンクを含めた改修）・検出 - インデックス未登録（内部リンクを増やす）・Google が知らない URL・重複・除外・その他に分ける。記事改修の指示文の記事の情報に、登録状態と改修の方針を入れる。画面「インデックスの登録状態」（`google.index-status`）に、分類ごとの件数と、登録済み以外の記事を出す。

**D-37-03 既存の記事の改修** 利用者の判断：今は表示回数の多い記事でも1日3回ほどで、タイトルを変えたときのクリック率の揺れは問題にならないため、全記事をまとめて改修の対象にしてよい（D-36-03 はこれで決定）。記事改修のまとめて実行で、「Google のインデックスに登録されていない記事」と「公開中の全ての記事」を選べるようにした（評価していない記事は、品質基準の全体を見て改修する）。インデックス未登録の記事を先に改修し、登録状態の変化を追う。Google への登録のリクエストは API ではできないため、必要なら Search Console の画面で行う。

### 項目38：WordPress の更新の確認（2026-09-29）

**D-38-01 確認の方法** WordPress 本体・プラグイン・テーマの更新を、BlogOS で毎日（04:45。`wordpress:check-updates`、画面の「今すぐ確認する」）確認する。インストール中のバージョンは、REST API（`/wp/v2/plugins`・`/wp/v2/themes`。BlogOS の WordPress のユーザーに管理者の権限がある）と、公開中の RSS の generator（本体）から読み、WordPress.org の公開の API（無料・登録不要）の最新のバージョンと比べる。WordPress.org で公開停止になったプラグイン（`error: closed`）と、WordPress.org にないもの（自作・有料）も見分ける。結果は `wordpress_components`（ブログごとの最新の状態）に持ち、トップページに更新・公開停止の件数を出す。

**D-38-02 更新は WordPress の管理画面で行う** REST API にはプラグインを更新する機能がなく、テーマの接続部分で更新させると、失敗したときにサイトが表示されなくなり BlogOS からは直せないため、BlogOS は知らせるだけにする。脆弱性のデータベース（Wordfence など）との照合は、必要になったときに検討する。

**D-38-03 si-note の整理** 確認の結果を受けて、利用者が無効のプラグイン（7件）と使っていないテーマを削除した（STINGER8 は Theme-SI-Original の元として残した）。有効な `html-on-pages`（固定ページの URL の末尾に .html を付ける）は、2023-07-24 に WordPress.org で公開停止（ガイドライン違反）になっており、2009年から更新されていない。si-note の固定ページの URL（/js.html、/js/js-basic.html、/contact.html など）はこのプラグインで作られているため、止めると URL が変わりリンク切れになる。同じ処理をテーマ（子テーマ）に移してからプラグインを止める案を出した。

### 項目39：公開された記事へのリンクの切り替え（2026-09-29）

**D-39-01 まだ WordPress にない記事を指す** 記事の目印に `[[記事:下書き123]]`（BlogOS の新規記事の編集案の番号）を加えた。公開されるまではタイトルだけにし、見えない目印 `<!-- blogos:下書き:123 -->` を残す。反映されて WordPress の記事になれば、その記事として扱う。記事改修・新規記事作成の指示文の記事の一覧に、作業中の新規記事の編集案も載せる（子カテゴリの記事・ロードマップの自動作成の前提）。

**D-39-02 公開されたらリンクに切り替える編集案を自動で作る** 同期（毎日・手動）の終わりと、BlogOS から公開の状態で反映に成功したときに、タイトルだけで載せている記事（`<!-- blogos:記事:ID -->`・`<!-- blogos:下書き:ID -->`）のうち、指している記事が公開されたものを探し、記事ごとにリンクに切り替える編集案を作る（`App\Services\Articles\LinkSwitchService`。AIは使わず、仕上げで置き換える。`article_drafts.auto_reason = link_switch`）。作業中の編集案がある記事には作らず、画面で知らせる（人の作業を上書きしないため）。WordPress への反映は、画面「リンクの切り替え」で人が確認してまとめて行う（1回20件まで。BlogOS が人の承認なしに WordPress を変えないため。利用者の判断）。まとめて反映は、この編集案だけにする。

### 項目40：記事の企画（2026-09-29）

**D-40-01 実行モード「記事の企画」** 子カテゴリの、まだ記事にしていない内容（記事の案：タイトル案・キーワード・検索意図・記事種類・ロードマップのステップ・優先度・理由・根拠の URL）と、親カテゴリの、足りない子カテゴリ（名前・スラッグ・範囲・位置・最初に書く記事の案）を、AIに出させる（`topic_planning`。テンプレート 1.0.0）。指示文には、カテゴリの構成と記事の数、親の下全体の記事のタイトルとメインキーワード（重複を避けるため）、作業中の新しい記事、カテゴリのロードマップ（固定ページ〈スラッグ〉.html）の学習のステップ、Search Console の検索語句（子カテゴリはそのカテゴリの記事の語句だけ）を入れる。Web 検索を使う API 実行を標準にする（公式の資料の目次・ほかの入門サイトの範囲と比べて抜けを見つける。1回約 $0.05。利用者の判断）。

**D-40-02 案の確認** 案は `topic_suggestions` に保存し、画面「記事の企画」で人が採用・見送りを選ぶ。同じカテゴリ・同じ種類の確認待ちの案は、企画し直すと置き換える。既存の記事・作業中の新しい記事とメインキーワードが同じ、またはタイトルにメインキーワードの語がすべて含まれる案には「重複の可能性」を付ける（子カテゴリの案は、同じ名前・スラッグのカテゴリ）。記事の案からは、キーワード・記事種類・カテゴリ・企画の理由を入れた状態で新規記事の作成の画面を開ける。子カテゴリの案を採用しても、WordPress のカテゴリはすぐには作らない（空のカテゴリを増やさないため。最初の記事をまとめて公開するときに作る。利用者の判断）。

**D-40-03 AIを使わない手がかり** 同じ画面に、記事の少ないカテゴリ（2件以下）と、記事が合っていない検索語句（直近90日で平均掲載順位が20位より下）を出す。記事のない「基礎」だけのカテゴリ（Java・Python など）と、AWS の記事のないカテゴリは、今後の自動作成で記事を作るため残す（利用者の判断）。

### 項目41：カテゴリの立ち上げ（2026-09-29。(a) ①〜⑤）

**D-41-01 流れ** 今後の検討事項 1〜4（子カテゴリ・記事・子ロードマップ・親ロードマップの自動作成）を、「カテゴリの立ち上げ」としてまとめる（利用者の判断）。①親カテゴリ → ②子カテゴリ（既存の子カテゴリ、または記事の企画で採用した子カテゴリの案）→ ③記事の企画（子カテゴリごとに10件）→ ④記事の編集案（採用した案から API でまとめて作る）→ ⑤子ロードマップの編集案 → ⑥初回の公開（人が選んだ5記事と子ロードマップ。WordPress のカテゴリはこのとき作る）→ ⑦残りの記事の公開（確認できたものから）→ ⑧親ロードマップ。各段階で人が確認・承認する。作る順番は (a) ①〜⑤、(b) ⑥・⑦、(c) ⑧ に分ける。親ロードマップの固定ページは、子ロードマップの URL（/親/子.html）を正しくするため、最初に WordPress の下書きとして作る（(b) で作る）。

**D-41-02 (a) の作り** `category_launches`（親カテゴリ・状態）と `category_launch_children`（既存の子カテゴリ、または子カテゴリの案。子ロードマップの編集案）に持つ。(a) は既存の親カテゴリから始める（新しい親カテゴリを WordPress に作るのは (b)）。③は記事の企画に「立ち上げの子カテゴリ」を渡し、WordPress にまだない子カテゴリでも、名前・スラッグ・範囲で企画させる（案は `topic_suggestions.launch_child_id` で結ぶ）。④は採用した案ごとに新規記事作成を API 実行し（タイトル案・ステップ・同じ子カテゴリの記事の一覧を補足に入れる。作成中の実行は `topic_suggestions.article_generation_id`、できた編集案は `article_draft_id` と `article_drafts.category_launch_child_id`）、残高の見込みなどで止まったら続けない。⑤は、すべての編集案がそろってから、編集案の目印（`[[記事:下書きN]]`）を並べて子ロードマップ（固定ページ・`child_roadmap`）を作らせる。費用の目安は子カテゴリ1つで約 $0.3。記事の企画のテンプレートに、人が提供した情報（補足）が入っていなかったため追加した（1.0.1）。

**D-41-03 (b) ⑥・⑦ 公開** 立ち上げの画面で、子カテゴリごとに公開する記事の編集案と子ロードマップを選び（初回は目印の残っていない5記事、2回目からは残りすべてを初めから選んでおく）、人が承認してまとめて公開する（`App\Services\Topics\CategoryLaunchPublishService`）。(1) WordPress にまだない子カテゴリを作る（`TermPushService::createCategory`。同じスラッグがあれば、それを使う）。(2) 記事の編集案にカテゴリ（とカテゴリのアイキャッチ）を設定し、仕上げ直して（公開済みになった記事へのリンクにする）公開する。(3) 子ロードマップは記事の後に公開し、親ロードマップの固定ページ（スラッグが親カテゴリのスラッグで親のページがないもの）の子のページにする（`article_drafts.wordpress_parent_id`。URL を /親/子.html にするため）。親ロードマップのページがなければ、WordPress の下書き（「〈親〉ロードマップ（準備中）」）として先に作り、⑧で中身を作るための作業中の編集案を残す（`category_launches.parent_roadmap_draft_id`）。先に公開した記事から後に公開した記事へのリンクは、リンクの切り替え（D-39）の編集案になる。新しい技術（親カテゴリ）は、立ち上げの画面から WordPress にカテゴリを作ってから始める（記事のないカテゴリは WordPress の画面には出ない）。

**D-41-04 (c) ⑧ 親ロードマップ** すべての子カテゴリの子ロードマップの編集案がそろってから、立ち上げの画面で作る（API 実行）。親ロードマップの固定ページ（なければ WordPress の下書きとして作る）の記事改修として作る（`CategoryLaunchService::generateParentRoadmap`）。BlogOS が仮に作った「準備中」のページ（WordPress の下書き）は全面改修で html-rules.md 2-1 の親ロードマップを初めから作り、公開中の親ロードマップ（例：/js.html）は構成の見直しで、新しい子ロードマップを学習の順番に合うステップに加える。子ロードマップは `[[記事:下書きN]]`（公開済みならリンク、未公開ならタイトルだけ）、立ち上げ以外の既存の子ロードマップ（親ロードマップの子のページ）は `[[記事:ID]]` で渡す。記事種類は、管理情報がなければ渡した値（`parent_roadmap`）を使う（`PromptBuilder`）。公開は、AI が中身を作った作業中の編集案（「準備中」のまま公開しないため）を、人が確認して「公開する」を押したときだけ行う。公開した時点で、立ち上げの記事・子ロードマップがすべて公開済みなら、立ち上げを「完了」にする。

### 項目42：ブログ全体の内部リンクの確認（2026-09-29）

**D-42-01 判定** 同期で本文から取り出した内部リンク（`internal_links`）から判定する（WordPress・外部のサイトにはアクセスしない。`App\Services\Articles\InternalLinkChecker`）。リンク切れ（ない URL・公開していない記事へのリンク・ないカテゴリの一覧）、古い URL（記事はあるが URL が今と違う。WordPress の転送で開けている）、カテゴリの一覧へのリンク（ロードマップのページがある場合だけ）、孤立記事（公開中のほかの記事からリンクされていない投稿・ロードマップのページ）、ロードマップに載っていない投稿（カテゴリの子ロードマップからリンクされていない）、内部リンクがない投稿（参考）。トップ・タグ・投稿者などの一覧へのリンクは問題にしない。ロードマップのページは、カテゴリの立ち上げ（D-41）・テーマのパンくずと同じ決まりで探す。画面は「内部リンクの確認」（/links/check）、トップページにリンク切れの件数を出す。si-note では、リンク切れ42件（ほとんどが仮の `xxx.html`）・古い URL 2件・カテゴリの一覧 2件・孤立記事69件だった。

**D-42-02 直し方** 古い URL とカテゴリの一覧（→ ロードマップ）は、リンクの切り替え（D-39）の編集案で機械的に直す（元のリンクと同じ書き方の URL にし、目印の切り替えがない記事は仕上げを通さない）。リンク切れは、記事改修の指示文の「この記事の内部リンクの問題」に入れ、AI に内容に合う記事の目印に置き換えるか外させる（まとめて実行の対象「内部リンクが切れている記事」）。孤立記事・ロードマップに載っていない記事は、記事改修・新規記事作成の指示文の記事の一覧で「（リンクが少ない記事）」の印を付け、関連記事・次に読む記事に優先して選ばせる（テンプレート 1.2.6）。外部のサイトへのリンク切れの確認は、今回は作らない（教材のリンクは毎週の確認がある。D-33-09）。

### 項目43：OpenAI API の1分あたりの上限（2026-09-29）

**D-43-01 待って送り直す** 本番で教材の調査をまとめて実行したときに、HTTP 429（1分あたりのトークン数の上限。gpt-6-luna は 20万トークン/分。Web 検索を含む教材の調査は1件で約6万トークン）で失敗した。1分あたりの上限の 429 は、OpenAI が案内する時間（`Retry-After` の見出し、なければメッセージの「try again in 9.353s」。読み取れなければ20秒）に1秒を足して待ち、送り直す（`OpenAiClient`）。待つ時間の合計は180秒まで（`MAX_RATE_LIMIT_WAIT`。超える場合は、今までどおり失敗として記録し、人が実行し直す）。断られたリクエストには料金がかからないため、D-24 の「失敗しても自動では再実行しない」（料金がかかるため）とは分けて扱う。クレジットの不足（`insufficient_quota`。これも 429）は、待っても直らないため送り直さない。API 実行の Job の時間切れは、待つ時間の分を足した。

**D-43-02 余分な閉じ括弧** 本番の教材の調査で、AI の回答の JSON が、全体を閉じた後にもう一度「}」を書いていた（余分な「,」もあった）ため、取り込めずに失敗した。余分な「,」を直しても読めない場合は、先頭の括弧から対応する閉じ括弧まで（文字列の中の括弧は数えない）を読む（`AiOutputParser::decode`）。取り込めなかった API 実行は、保存済みの回答で取り込み直せる（料金はかからない。D-30-10）。

### 項目44：定期実行の確認と変更（2026-09-30）

**D-44-01 画面** 定期実行の時刻と内容が、プログラム（`routes/console.php`）に固定で書かれ、画面から確認・変更できなかった（利用者の指摘）。画面「定期実行」（/scheduled-tasks）で、9つの定期実行の内容・時刻・次の実行・前回の結果を確認し、時刻（毎日／毎週と曜日、分の単位）と有効・無効を変えられるようにした。一覧と既定の時刻は `App\Support\ScheduledTasks`、変えた設定は `scheduled_task_settings` にあり、Scheduler への登録は `bootstrap/app.php` の `withSchedule` から `ScheduledTaskService::register()` で行う（`schedule:run` のたびに設定を読むため、サーバーでの作業は要らない）。古い記録の削除は、止めると記録が増え続けるため無効にできない。自動の再評価と教材の定期チェックは、料金がかかるため、有効・無効は AI の設定で決め（D-25・D-30）、この画面では時刻だけを変える。前に終わっていてほしい定期実行（Google の取得・インデックスの確認・自動の再評価などは同期の後）より前か同じ時刻にすると、前回かかった時間も含めて注意を出す（保存はする）。「今すぐ実行」は Queue で1回実行する（料金がかかる2つは出さない。実行中なら重ねない）。1時間ごとなどの細かい設定は作らない。

**D-44-02 実行の記録** 実行ごとに `scheduled_task_runs` に、開始・終了・かかった時間・予定の時刻（開始の遅れ）・きっかけ（定期実行／今すぐ実行と、実行した人）・処理件数・変更件数・問題の件数・対象のブログの数・コマンドの出力・失敗の理由・メモリの使用量を残す（利用者の依頼。開始・終了と件数以外の項目は開発が選んだ）。定期実行は Scheduler の中でコマンドを実行し、実行中の記録の ID を Context に入れる。コマンドは件数を `report()` で加える。同期と Google の取得は Queue に登録するだけのため、登録した処理が Context を受け継ぎ、処理が終わったときに件数を加え、すべて終わった時点で記録を閉じる（終了の時刻が、実際の処理の終わりになる）。定期実行から登録した同期・取得の失敗は、定期実行の記録に失敗として残す（Queue の失敗にはしない）。3時間以上「実行中」のままの記録は、途中で止まった可能性として表示する。トップページで、前回が失敗した定期実行と、26時間以上定期実行が動いていないこと（cron の停止の疑い）を知らせる。コマンドを SSH で直接実行した場合は記録しない。記録は1年で削除する。

**D-44-03 XServer の MySQL の timestamp** 本番（XServer）の MySQL は、1つのテーブルで2つ目以降の、初期値のない NOT NULL の `timestamp` 列を作れない（初期値が 0000-00-00 になり、エラー 1067）。`scheduled_task_runs.started_at` は null を許す形にした（値は必ず入れる）。今後の Migration でも、`timestamp` 列は `nullable()` にする。途中で止まった Migration をやり直せるよう、1つ目のテーブルがあれば作らない。

### 項目45：教材のカテゴリでの絞り込み（2026-10-01）

**D-45-01 カテゴリで見る** 教材は記事のカテゴリで候補が決まるため、「カテゴリで絞り込む → 記事に使える教材を確かめる → 足りなければ増やす」の流れにする（利用者の提案）。教材の画面に、カテゴリごとの公開中の記事の数と、種類ごとの記事で紹介に使える教材の数（このカテゴリに登録＋親カテゴリに登録）の表を出し、記事があるのに0件の種類を赤字にする。カテゴリで絞り込むと、親カテゴリに登録した教材も出す（記事で候補になる範囲と同じ）。教材ごとに「記事で紹介に使えるか」と使えない理由（状態が有効でない・情報を調べていない・提携中でない）を出す（判定は MaterialMatcher と同じ条件。`MaterialCoverageService`）。カテゴリを選んだときは、そのカテゴリを入れた候補探し（D-30）への導線を出し、調べていない教材は今までどおり一覧でチェックしてまとめて調べる。新しいテーブルは作らない。

### 項目46：タイトルが変わった記事へのリンクの文字（2026-10-01）

**D-46-01 リンクの文字を追いかけて直す** 記事へのリンクの文字は、仕上げのときのリンク先のタイトルで決まるため、後からリンク先のタイトルが変わると、ほかの記事の編集案と公開中の記事で古いタイトルのままになる（利用者の指摘）。順番（先にタイトルを直す）で合わせず、リンク先のタイトルが変わったら BlogOS が追いかけて直す（`ArticleLinkTextUpdater`）。リンクの文字が、リンク先の記事の以前のタイトル（同期・反映の履歴の `title_raw`）と同じものだけを直し、人が書いた文字や、中に別のタグがあるリンクは変えない。作業中の編集案は、同期・反映の後にその場で直す（WordPress は変えない。反映の結果待ちは除く。直したことは仕上げの注意と履歴に残る）。仕上げ（「目印を置き換え直す」を含む）でも直す。公開中の記事は、リンクの切り替え（D-39）の編集案で直し、人が確認して反映する（作業中の編集案がある記事は、その編集案で直るため作らない。仕上げを通さず、リンクの文字だけを直す）。全記事の改修をやり直す間は、ほとんどの直しが改修の編集案に取り込まれる。

### 項目47：評価の作り直し（品質基準 2.0.0。2026-10-03）

**D-47-01 方針** 合計点だけでは何に強く何に弱いかが分からず、大きな項目（例：SEO 10点）では何がどう悪いか・改修で直ったかを示せなかった（利用者の指摘。ChatGPT の意見も参考にした）。評価を4層（①公開の条件 ②総合品質100点 ③観点ごとの適合度 ④実績）にし、点数の分け方より、指摘 → 改修での対応 → 改修後の確認を1本の線でつなぐことを中心にする。進め方：S1 品質基準 2.0.0（案は `docs/BLOGOS_QUALITY_STANDARD_2_DRAFT.md`）、S2 品質診断の指摘と観点の表示、S3 記事改修の対応表と改修後の確認、S4 実績の画面。S3 まで全記事の改修のやり直しは待つ。

**D-47-02 品質基準 2.0.0（S1）** 共通基準 `scoring.md`：採点項目を確かめられる単位に分け、全項目に ○・△ の判定の基準と観点を付けた。11分類（①検索意図15 ②記事の型15 ③理解15 ④網羅10 ⑤正確10 ⑥検索結果・SEO12 ⑦独自価値8 ⑧信頼5 ⑨読みやすさ4 ⑩回遊3 ⑪収益化3）。観点は9つ（`intent` `type` `people` `seo` `ctr` `reader` `ux` `nav` `mon`）で、1つの判定を関係する観点に集計する（別々に採点しない）。People-first は Google の評価そのものではない。si-note `article-types.md` 6章：記事の型（Know・Do・Solve・Compare・実践は細分類、ロードマップ・収益記事は記事種類）ごとの項目（各15点）、★（必須）の項目が × なら点数に関係なく公開不可、観点の重要度（改善の優先度に使う）。細分類が未登録の集客記事は、記事の型の項目を対象外として換算する。収益化は、合う教材がなく紹介しない記事では ○（導線を増やすほど高得点にしない）。共通・si-note とも 2.0.0。

**D-47-03 品質診断の指摘と観点の表示（S2）** 品質診断（テンプレート 2.0.0）は、判定の基準で判定し、○ でない項目ごとに指摘（どこが・何が足りないか・どう直すか）を出す。`article_evaluations` に細分類・★の × の項目・観点ごとの適合度を、`article_evaluation_details` に指摘を保存する。評価の画面に、★の判定、観点ごとの適合度（重要な観点が90%未満なら目立たせ、下げている項目を出す）、項目ごとの判定の基準と指摘を出す。記事改修の指示文の「優先して改善する点」に、指摘をそのまま入れる。まだ WordPress にない新規記事の編集案の品質診断は、編集案を作ったときの記事種類・細分類で採点する（新規記事の作成に `細分類の値` を渡す）。品質基準のバージョンが変わったため、全記事が再評価の条件に当てはまる。

**D-47-04 指摘への対応と改修後の確認（S3）** 記事改修を始めるときに、元にした評価（編集案の最新の評価、なければ記事の最新の評価）の ○ でない項目（要人間確認を除く）に番号を付け、「直すべき指摘」として指示文に入れ、`revision_findings` に記録する。記事改修（テンプレート 1.3.0）は「=== 指摘への対応 ===」で番号ごとに、直した・一部直した・直さなかった（理由）を出す（人の実体験など AI が用意できないものは、推測で作らず理由を書く）。編集案の品質診断（テンプレート 2.1.0）は、前回の改修の指摘ごとに、解消・一部解消・未解消と確かめた結果を出す（まとめて実行の改修の後の診断（D-27）でも行う）。編集案の画面に「指摘と対応」（改修前・改修後の点数と観点ごとの適合度、指摘 → 対応 → 改修後の判定、件数）を出す。未解消・一部解消の指摘は、改修後の評価の指摘として、次の改修に引き継がれる。

**D-47-05 実績の画面（S4）** 画面「記事の実績と次にやること」（/analytics/performance）に、公開中の記事ごとの Search Console（表示回数・クリック・クリック率・平均掲載順位）・GA4（ページビュー・エンゲージ率）の数字（直近28日か90日。Search Console のデータがある最後の日まで。前の同じ長さの期間と比べる）、インデックスの登録状態、最新の品質評価（点数と、検索結果で選ばれるか・検索意図の適合度）を並べる（`ArticlePerformanceService`）。実績は点数にせず、決まった規則で次にやることを出す：インデックス未登録、クリック率が低い（表示100回以上で、掲載順位ごとの一般的な目安の半分より低い）、評価は高いのにクリック率が低い（検索結果で選ばれるかの適合度が90%以上なのに低い → 検索結果・検索意図を見直す）、あと一歩で1ページ目（11〜20位・表示50回以上）、読まれずに離脱（セッション30以上でエンゲージ率40%未満）、検索結果にほとんど表示されない（登録済みで表示10回未満）、クリックが減っている（前の期間10回以上から3割以上減）。次にやることがある記事を先に、表示回数の多い順に並べ、記事ごとに改修案の作成へのリンクを出す。アフィリエイトの収益（保留）と AdSense の記事ごとの収益（API から得られない。D-21-09）は入れない。

### 項目48：自動の再評価の順番（2026-10-03）

**D-48-01 条件はそのまま、順番を問題の大きさにする** 再評価の条件（D-25-04：未評価・記事の更新・品質基準とテンプレートの更新・リンクの増減・アクセスの減少・定期的な見直し）は、何かが変わったときだけで、品質基準 2.0.0 の観点の点数は条件に入れない（変わっていない記事の診断し直しは、AI のぶれしか生まないため。利用者の判断）。1日の上限の中でどの記事から再評価するかは、理由の順・記事の順から、問題の大きさの順に改めた（品質基準 2.0.0 でほぼ全記事が「品質基準の更新」に当てはまり、記事の ID の順に処理されるため）。1段目：インデックス未登録・前回の点数が40点未満（全面改修が必要）・記事の型の★の項目が ×、2段目：前回の点数が70点未満（構成の見直しが必要）・まだ評価していない、3段目：それ以外。同じ段の中では理由の順、その後は直近の Search Console の表示回数の多い順（`ReevaluationDetector`）。インデックス未登録は、きっかけ（条件）には加えず、順番にだけ使う。まとめて実行の「再評価の条件に当てはまる記事」・AI の設定の今日の対象・`ai:auto-reevaluate --dry-run` に、優先の理由を表示する。

### 項目49：テーマの切り替えとフォルダの構成（2026-10-04）

**D-49-01 テーマは画面「設定」で選ぶ** 使うテーマを `config/blogos.php` の `theme` から、画面「設定」→「画面のテーマ」で選ぶ形に改めた。選んだテーマは BlogOS 全体の設定（ブログごとではない）として、新しいテーブル `system_settings`（キーと値。最初は `theme` だけ）に保存する。選んでいない・登録から消えたテーマが保存されている場合は、`config/themes.php` の `default`（`blank`）で表示する。本番も、選ぶまでは blank のまま。ironman は制作中（`status` が `wip`）と表示し、選ぶことはできる（利用者の判断）。

**D-49-02 テーマの登録と、共通の画面の上書き** テーマは `config/themes.php` に登録する（名前・説明・状態）。`resources/views/` の画面を共通の画面とし、`resources/views/themes/{テーマ名}/` に同じ名前の View を置いた場合だけ、そのテーマのときにそちらを使う（`ApplyTheme` が View を探す場所の先頭に加える）。これまでトップページだけがテーマごとの View（`themes/blank/index`・`themes/ironman/index`）で、ironman のトップページはアークリアクターだけでお知らせ・各画面への入口がなかったため、トップページを共通の `dashboard/index`・`dashboard/content` に移し、ironman はアークリアクターを加えて `dashboard.content` を読み込む形にした。blank は何も上書きしない。CSS・JavaScript はこれまでどおり `public/themes/{テーマ名}/css/style.css`・`js/script.js` を全画面で読み込み、更新日時を付けてブラウザの古いキャッシュを使わないようにした。`<body>` に `theme-{テーマ名}` を付ける。テーマの追加の手順は `resources/views/themes/README.md`。

**D-49-03 テーマの考え方** blank：装飾なし。機能を作ったときに、とりあえず出して確かめるためのテーマ。ironman：映画「アイアンマン」の世界観（アークリアクター・HUD）。共通の画面の `style="…"` の直接の指定は、テーマの CSS で変えられるように直す（D-49-04）。

**D-49-04 ironman の作り込みの進め方と、共通の CSS（T1）** ironman は次の順で作り込む（利用者の了承。2026-10-04）：T1 土台（共通の画面をテーマで変えられる形にする。blank の見た目は変えない）→ T2 ironman の基本（全画面の色・文字・部品・ヘッダー、スクロールできない・背景が白いままの問題の修正、不要なファイルの整理、動きを減らす設定への対応）→ T3 トップページ（アークリアクターと状態のパネル。アークリアクターは作成途中で、機械感・大きさなどをここで作り直す）→ T4 よく使う画面から順に仕上げ → T5 スマートフォン・読みやすさ・負荷。色は HUD の青白い光を基本にし、スーツの赤と金をボタン・注意・要対応だけに使う。大きな動きはトップページのアークリアクターだけにする。書体は英字の見出しに Orbitron か Rajdhani、日本語に Noto Sans JP（Google Fonts）。T1 として、どのテーマでも最初に読み込む共通の CSS `public/css/blogos.css` を追加し、共通の画面の `style="…"` のうち、テーマで変えたい見た目（文字の色・行の強調・背景・枠線・等幅）を意味ごとの class（`text-muted`・`text-error`・`text-warn`・`text-ok`・`row-attention` など）に、表の `border="1" cellpadding="4" cellspacing="0"` を `table.data` に、共通のヘッダーの見た目を class に置き換えた。幅・余白・文字の寄せなど画面ごとの配置は `style="…"` に残す。置き換えの前後で、blank の54画面の表示が同じであることを画像で確かめた。ログイン画面とサイト内検索の画面は、共通の枠（`layouts.app`）を使わないため対象外。

**D-49-05 ironman の基本の見た目（T2）** ironman の CSS を作り直した：色・書体・光を変数にまとめ（`tokens.css`）、全画面の部品（`components/` の header・text・table・form・box）を追加し、共通の画面の class を上書きする。全画面に効いていた「高さ100%・はみ出しを隠す」（長い画面がスクロールできない）と、背景が白いまま文字だけ水色の問題を直し、アークリアクターはトップページの本文の中の領域に収めた。共通の枠に本文の枠 `<main class="site-main">` を加えた（blank では何もしない）。中身が空の CSS 7つ・使われていない `card.css`・使われていない Animation 4つ・同じ名前の Animation の重複を整理し、定義のなかったページの表示の Animation を定義した。OS の「アニメーションを減らす」を選んでいる場合はすべての動きを止める。`images/arc-reactor.png` は画面では使っていないが、アークリアクターの完成のイメージとして T3 で使うため残す。

**D-49-06 アークリアクターの作り直し（T3-1）** アークリアクターを、div を重ねる CSS（約60個の要素・約30の CSS ファイル・JavaScript）から、1つの SVG（`themes/ironman/components/reactor.blade.php`）に作り直した（利用者の了承。2026-10-04）。形は参考画像（`images/arc-reactor.png`）を基準にする。金属は黒に近い鏡面の金属とし、鋭い白い映り込みの帯（輪ごとに向きを変える）と、コアの青い光の映り込みで金属に見せる（利用者の指摘：最初の明るい灰色の金属は陳腐に見えた）。外から、段になった鋼の外枠（外の縁・外の帯・内の帯・内の縁、継ぎ目とボルト各10か所）、10個のコイル（土台の板・一段高い縁・窓の上下の棒・左右の留め金・ねじと、コイルの間の支え。窓の中を、外枠の HUD と同じ蛍光色の電気が流れる：うっすら光り続ける線の上を、短い光と、ときどき明滅する長い光が流れる）、コアを囲む金属の輪、金属で縁取った三角のコア（Mark VI。後ろに広がる光、光る帯、中を通る明るい線）、金属の輪で囲んだ中心の光、前面のガラスの映り込み。金属は動かさず、光の脈打ち・コイルの青い線の流れ・周りの HUD の円の回転だけを CSS で動かす。座標は、角度と半径から Blade の中で求める。光の色は CSS の変数で決め、`$reactorState`（normal：青／warning：金／critical：赤。赤は脈打ちと線の流れを速くする）と `$reactorBusy`（HUD の円を速く回す）で変える。SVG の部品の名前（id）は、リアクターごとに変える。コイルは部品（`reactor-coils.blade.php`）に分けた。形を2つ用意し、`config/themes.php` の ironman の `reactor_variant`（または `$reactorVariant`）で選ぶ：1＝基本、2＝コアの一番外側に三角の金属（頂点を留め具でコアを囲む金属の輪につなぐ）と、コアの背面に外側と同じコイルを小さくして逆向きに回すものを足した形。利用者が 2 を採用した（2026-10-04）。2 の外側の三角の金属は、辺の太さをコアを囲む金属の輪と同じ 13 にする。コア（中心の光・コアの光・三角）の脈打ちは、利用者の指摘（変化が弱い）で強くした：3秒ごと（赤は1.5秒）に、速く明るくなってゆっくり落ち、中心の光は少しふくらむ。大きさは直径 240〜340px。古いリアクターの CSS・JavaScript は削除した。トップページの状態のパネルと、状態とリアクターの色のつなぎ込みは T3-2・T3-3 で行う。

**D-49-07 トップページの状態のパネルと入口（T3-2・T3-3）** トップページのお知らせと同じデータから、領域ごとの状態（問題なし・注意・要対応）を決める `DashboardStatusService` を追加した（同期：失敗は要対応、未解決の問題・未同期は注意／定期実行：停止の疑い・失敗は要対応／WordPress：公開停止のプラグインは要対応、更新は注意／AI の残高：止まる見込み・料金表の読み取り失敗は要対応、残高の注意・未登録・値下がりの確認待ちは注意／内部リンク：リンク切れは要対応、切り替え・修正の編集案は注意／教材・提携：提携終了の疑いは要対応）。全体の状態は最も悪いパネル（要対応があれば critical、注意があれば warning）で、同期の開始待ち・実行中は busy とする。各画面への入口は `App\Support\DashboardLinks` の1か所にまとめ（記事・AI・収益・画像・分析・管理）、どのテーマでもここから出す（blank は種類ごとに1行）。共通のトップページは、お知らせ（`dashboard/notices`）と入口（`dashboard/links`）の部品に分け、同期の「今すぐ同期」（`partials/sync-run-form`）と実行中の表示の更新（`partials/sync-poll`）も部品にした。ironman のトップページは、SYSTEM STATUS の帯、アークリアクター（全体の状態の色、busy で HUD の円が速く回る）と左右3つずつの状態のパネル（同期のパネルに「今すぐ同期」）、お知らせ（同期を除く）、種類ごとの入口のパネル。狭い画面では、アークリアクター → パネルの順に縦に並べる。

**D-49-08 テーマの CSS の読み込みと、黒い背景・白い文字** ironman を選んでも背景が白いままになる問題があった（利用者の指摘）。テーマの入口の style.css には更新日時を付けていたが、そこから @import で読み込む CSS（base.css・background.css など）には付いておらず、ブラウザが以前の版（背景を白のままにしていた版）を使い続けたため。テーマの CSS は、style.css の @import を BlogOS が読み取り、1つずつ更新日時を付けて `<link>` で読み込む形に改めた（`ThemeService::stylesheets`。style.css には @import だけを書き、読み込まれる側の CSS では @import を使わない）。あわせて、ironman は全画面の背景を黒、文字の基本を白とし、トップページのパネルの背景を透明にした。パネルの文字・リンクは白で、状態を表す部分（英字の札・光る点・状態の英字・今の値・枠の色）だけを状態の色にする（利用者の判断）。

**D-49-09 アークリアクターから状態のパネルへの線** パソコンの幅（1025px 以上。パネルがアークリアクターの左右に並ぶとき）では、アークリアクターの外枠の縁から各パネルへ、斜めに出て横に折れる HUD の線を引く（利用者の要望。狭い画面では引かない）。線の色はパネルの状態の色で、光がアークリアクターからパネルへ流れる。パネルの高さは中身で変わるため、表示した後に位置を測って描き、大きさが変わったら描き直す（`public/themes/ironman/js/dashboard/connectors.js`）。テーマの画面ごとの JavaScript は、更新日時を付けるため、テーマの View から `ThemeService::assetUrl` で読み込む（`script.js` の `loadScript()` は更新日時が付かない）。

**D-49-10 パネルの背景** トップページのパネルの背景を、透明（D-49-08）から、パネルの状態の色をうっすら含んだ色に改めた（利用者の判断：ironman の世界観に合う）。上ほど少し濃く（約11% → 4%）、内側にかすかな光を入れる。状態の色が変わると、背景の色も変わる。

**D-49-11 画面の区切りとボタンの種類（T4）** T4 は、よく使う画面から順に、共通の画面に意味の class を加えて ironman の CSS で見た目を決める（blank の見た目は変えない）。区切りは `.panel`（`<section>`・`<fieldset>`・`<form>` に付ける。最初の h2・legend が見出しの行になり、`data-code` の英字の札を前に出す。トップページのパネルと同じ土台：`components/panel.css`）。ボタンは、何も付けない＝主な操作（スーツの赤と金）、`btn-secondary`＝補助の操作（HUD の青白い線）、`btn-danger`＝取り消せない・壊す操作（赤い線と赤い文字）、`a.button-link`＝主な操作へのリンク。最初に「編集案」の画面に入れた（指摘と対応・画像と目印・編集・状態と反映・変更履歴を区切り、「破棄する」を btn-danger、「作業中に戻す」「目印を置き換え直す」を btn-secondary、「反映の確認へ」を button-link）。続けて、記事の一覧（絞り込みの欄）・記事の詳細・AI のまとめて実行（条件の欄・対象・記事ごとの結果）・評価の作成と詳細に入れた（記事の実績は、区切りもボタンもないため、変更なし）。見出し（h2）ごとの区切りはスクリプトで付け、blank の表示が変わらないことを画像で確かめた。

**D-49-12 ironman の完成（T4 の残り・T5）** 残りの全画面に、見出し（h2）ごとの区切り `.panel`（すでに `<section>` の中にある見出しは、その `<section>` に付ける）と、ボタンの種類（削除・破棄・解除・取り消しは btn-danger、絞り込み・確認・調べる・今すぐ実行・不採用・見送り・コピー・ログアウトなどは btn-secondary）、一覧の絞り込みの欄の FILTER のパネルを入れた。blank の全54画面の表示が変わらないことを画像で確かめた。T5 として、狭い画面（640px 以下）で表の文字と余白・見出し・パネルの余白を小さくし、いちばん目立たない文字の色を、黒い背景で読める明るさにした。CSS は約15ファイルと Google Fonts で、負荷の問題はない。ironman の状態を「使用できる」（`config/themes.php` の `status` を `ready`）にした。


### 項目50：表を横スクロールなしで見せる（2026-10-04）

**D-50-01 表は画面の幅に収め、収まらなければカードの形にする** 項目の多い表で、表の最下部までスクロールしてから横にスクロールし、また上に戻って確かめるのは不親切なため（利用者の指摘）、どのテーマでも、どの画面でも、表を横スクロールなしで見せる。(1) 一覧の表（`table.data`）は画面の幅に収める（セルの中の長い英数字・URL も折り返す。入力欄は置かれている場所の幅まで、fieldset は中身の幅より縮めてよい）。(2) それでも収まらない表と、列が細くなりすぎる表（見出しのセルが約2文字分より細い、または4行以上に折れる）は、共通の JavaScript（`public/js/blogos.js`）が `table.data.stacked` にし、行ごとのカードの形（各値の前に列の見出し）で見せる。表示した後に測り、画面の大きさが変わったとき・折りたたみを開いたときに測り直す。見た目は `public/css/blogos.css`（blank）と ironman の `components/table.css`。全画面を、幅 520px・1024px・1280px で、ページ全体と横スクロールの枠のはみ出しがないことを確かめた。


### 項目51：ログイン画面のテーマ（2026-10-04）

**D-51-01 ログイン前の画面にもテーマを当てる** ログイン画面は、ヘッダー（設定・ブログの切り替え）を出せないため共通の枠（`layouts.app`）を使っておらず、テーマが効いていなかった。ページの頭（共通とテーマの CSS・JavaScript の読み込み）を `layouts/head` に分け、ヘッダーのないログイン前の画面の枠 `layouts/guest`（`<body>` に `page-guest`）を加えて、ログイン画面をその枠にした。入力欄は `auth/login-form` に分け、ironman は `themes/ironman/auth/login` で、画面の中央にアークリアクター・BlogOS の名前（SYSTEM ACCESS）・入力欄のパネル（LOGIN）を並べる（`components/login.css`）。テーマを当てるとき（`ThemeService::apply`）に、前に見つけた View の場所を忘れさせる（同じ処理の中で2回目に開いた画面にも、テーマの View を使うため）。


### 項目52：残りの画面のテーマ（2026-10-04）

**D-52-01 サイト内検索とエラーの画面** テーマが効いていない画面を洗い出し、サイト内検索とエラーの画面に当てた。サイト内検索は共通の枠（`layouts.app`）にし、検索の欄を SEARCH のパネル、結果を一覧の表（`table.data`）にした（見出しの「si-note.com」の決め打ちは、選択中のブログの名前にした）。エラーの画面（401・403・404・419・422・429・500・503）は、ログイン前でも出るため、ヘッダーのない枠（`layouts.guest`）の共通の形 `errors/page` で、何が起きたか・どうすればよいか・トップページ（ログイン前はログイン画面）への入口を出す。400番台は、BlogOS が添えた日本語の理由（`abort(404, 'ブログが選択されていません。')` など）も出し、Laravel が自動で付ける英語の理由と500番台の理由は出さない。どこからも使われていない Laravel の初期の `welcome.blade.php` は削除した。編集案のプレビューの中身（WordPress の記事をブログのテーマで表示する）と画像のファイルは、テーマの対象外とする。


### 項目53：カテゴリの一覧（2026-10-04）

**D-53-01 カテゴリの一覧の画面** カテゴリを確認する画面がなかったため（編集の画面は DB 確認の画面からしか開けなかった）、画面「カテゴリ」（/categories）を加えた（利用者の要望）。親子の順に、名前（カテゴリのページへのリンク）・説明（未設定はそう表示）・スラッグ・記事の数（そのカテゴリに直接付いている記事。WordPress の「カウント」と同じ）を並べ、各行の「編集」から、既存の編集の画面（承認して WordPress へ反映、反映の直前の競合の確認）で更新する。新しいカテゴリの登録の画面は作らず、「カテゴリの立ち上げ」で作る（利用者の判断）。トップページの入口（記事）に加えた。

**D-53-02 スラッグを変えるときの警告と確認** si-note の記事の URL は「/親カテゴリのスラッグ/子カテゴリのスラッグ/記事のID.html」のため、カテゴリのスラッグを変えると、カテゴリのページと、そのカテゴリ・子孫のカテゴリの全記事の URL が変わる。編集の画面で、影響する記事の数（重複は1件）と URL の例（入力に合わせて変わった後の URL も）、検索エンジンの評価・外部からのリンク・Search Console・内部リンクへの影響を出し、「URL が変わることを理解したうえで変更します」のチェックと、反映の前の確認の画面を経てから反映する。チェックがなければ、BlogOS は反映を受け付けない（タグも同じ。タグのページの URL が変わる）。

**D-53-03 カテゴリの説明の扱い** 説明は、今のブログのテーマ（Theme-SI-Original）のカテゴリのページ（archive.php）には表示していない。AIOSEO の設定によっては、カテゴリのページの検索結果の説明文（meta description）に使われる。検索順位を直接上げるものではなく、カテゴリのページを検索結果に出す場合の説明文、またはテーマで表示する場合のページの紹介文として意味を持つ。BlogOS では、教材を AI で調べるときの手がかり（MaterialPromptValues）に使う。


### 項目54：ヘッダーのログアウト（2026-10-04）

**D-54-01 ログアウトはヘッダーのアイコン** トップページの最下部にあったログアウトのボタンをやめ、ヘッダーのブログ名のボタンの右隣に、ログアウトのアイコン（電源の形。押すとログアウトしてログイン画面に戻る）を置いた（利用者の要望）。ログイン後の全画面で使える。blank は枠線のある小さなボタン、ironman は設定・ブログ名と同じ HUD の札で、マウスを乗せると赤く光る。


### 項目55：トップページのブログ名（2026-10-04）

**D-55-01 ブログ名はヘッダーだけに出す** 選択中のブログの名前はヘッダーに出ているため、トップページの SYSTEM STATUS の帯の右と、お知らせの先頭の「現在のブログ：」をやめた（利用者の判断。blank も同じ）。ブログを選んでいないときだけ、お知らせに「ブログが選択されていません。画面上部のボタンから選択してください。」と出す。


### 項目56：ヘッダーの下のメニューバー（2026-10-04）

**D-56-01 メニューバー** どの画面からでも開けるよう、ログイン後の全画面のヘッダーの下にメニューバーを置いた（利用者の要望）。トップページの入口のパネルと同じく、種類ごとに英字の札と名前（例：SETTING 設定）を並べ、押すとその下に項目が開く（`<details>`。1つを開いたら他を閉じ、外を押したとき・Esc で閉じる：`public/js/blogos.js`）。項目は `App\Support\MenuItems` に1行を加えて増やす。最初は「SETTING 設定 → 画面のテーマ」（設定の画面の画面のテーマの欄 `#theme`）だけ。ironman では、ヘッダーとメニューバーをまとめた枠（`.site-top`）をスクロールしても上に残し、画面の中の場所へ移動したときに移動先の頭が隠れないよう、上に間を空ける。


### 項目57：テーマ切替ポップアップ（2026-10-04）

**D-57-01 テーマの切り替えはポップアップ** 設定の画面の「画面のテーマ」の選び方を、ヘッダーのブログ切替ポップアップと同じ形（選択肢の枠に名前・説明・使用中、「切替」「キャンセル」）のテーマ切替ポップアップに改め、ヘッダー（`layouts/header`）に置いて全画面で開けるようにした（利用者の要望）。メニューの「SETTING 設定 → 画面のテーマ」で開く（`data-modal-open="theme-switch-modal"`。画面は移らない）。背景・「キャンセル」・Esc で閉じる。切り替えた後は、開いていた画面に戻る。設定の画面からは、画面のテーマの欄をなくした。ポップアップには説明の文と「← 使用中」の印を出さない（使用中のテーマは選択済みで分かる。利用者の判断）。メニューの英字の札（SETTING）は、トップページの入口と同じく、blank では出さず名前だけにする。


### 項目58：音声の操作（ジャービス。2026-10-05）

**D-58-01 方式と進め方** BlogOS を声で操作できるようにする（利用者の要望。映画「アイアンマン」のジャービスのイメージ）。方式は3つを切り替えられる前提で、C（聞き取りも返事も OpenAI。順番に処理し、1往復ずつ）をメインにし、リアルタイムにしたくなったら D（リアルタイム会話 mini）、物足りなければ E（同 標準）を試す（利用者の判断）。段階1：C の方式で、見る道具だけ・画面・設定・費用の記録。段階2：操作する道具（確認つき）。段階3：D・E（リアルタイム会話）。各段階で、利用者が実際に話して試してから次に進む。

**D-58-02 方式 C の流れ（段階1）** ブラウザで、ヘッダーのマイクのボタン（ironman はトップページのアークリアクターも）を押してから、もう一度押すか、話し終えて1.5秒黙るまで（1回30秒まで）を録音し（`public/js/voice.js`）、BlogOS に送る（`POST /voice/turn`。1分10回まで）。BlogOS は、OpenAI で文字にし（gpt-transcribe。BlogOS の言葉とカテゴリの名前をヒントにする）、文章の AI（gpt-6-luna。推論なし）に道具（`App\Services\Voice\VoiceTools`）を渡して返事を決め（道具を呼んだら結果を渡して判断し直す。最大4回）、返事を声にして（gpt-4o-mini-tts。選んだ声と話し方の指示）返す（`VoiceService`）。ブラウザは、聞き取った文字と返事を会話の欄に出し、声を再生し、画面を移る命令なら再生の後に移る。直前の4往復をセッションに覚え、「それを開いて」のような続きの発言に使う。状態は `<body>` の class（voice-listening・voice-thinking・voice-speaking）で表し、ironman ではアークリアクターの光と回転が変わる。返事が AI の音声であることを会話の欄に出す（OpenAI の利用規約）。

**D-58-03 道具（段階1：見るだけ）** 画面を開く（トップページ・設定と、トップページの入口 DashboardLinks の画面）、今の状況（トップページの状態のパネル。`DashboardDataService` をトップページと共通にした）、AI の残高と今月の費用、記事の件数（インデックス未登録・点数の低い記事・未評価・作業中の編集案）、記事を探す・開く。データを書き換える道具は持たない。WordPress への反映・削除・承認は声ではしない（段階2の操作も、確認を挟む）。

**D-58-04 設定と費用** 設定は画面「AIの設定」の「音声の操作（全ブログ共通）」（system_settings の voice.*：有効・方式・声（13種類。初期値 cedar）・話し方の指示）。D・E は「準備中」で選べない。マイクのボタンは、有効にしていて OpenAI の API キーがあるときだけ出す。マイクは HTTPS か localhost の画面でだけ使える（ローカルの Herd は `herd secure blogos`）。1回のやり取りを `voice_turns` に残し（聞き取った文字・返事・道具・録音の長さ・モデル・トークン数・費用）、費用は「AIの費用と残高」の残高の見込みと今月の費用・月の上限に含める。実行の前に、1回の最大の費用で残高と上限を確かめる。音声のモデルの料金は、config/blogos.php の voice.prices（2026-10-05 に公式の料金ページで確認）で計算し、毎日の公式ページとの照合には段階3で加える。返事の声の費用は、文字数から話す時間を見積もる（1秒7文字）。

**D-58-05 確認つきの操作（段階2）** 声でできる操作として、同期の開始（start_sync）と、品質診断のまとめて実行（run_quality_diagnosis。対象は点数未満・未評価・再評価の条件・インデックス未登録、1回30件まで、モデルと推論の深さは品質診断の標準）を加えた（`App\Services\Voice\VoiceActions`）。操作の道具を呼んだ時点では実行せず、内容と費用の目安（同じモデルの過去の品質診断の平均×件数）を「確認待ち」としてセッションに覚え、AI がそれを伝えて尋ねる。利用者が次の発言で同意したら confirm_action で実行し、断ったら cancel_action で消す。確認待ちは90秒で消え、同じ発言の中での確認（AI が尋ねずに済ませる）と、選択中のブログが変わった後の確認は BlogOS が断る。実行は、画面のボタンと同じ処理（SyncDispatcher・AiBatchService）で、品質診断は登録後にまとめて実行の画面を開く。WordPress への反映・削除・承認は、引き続き声ではしない。

**D-58-06 リアルタイム会話（段階3。方式 D・E）** 方式 D（gpt-realtime-2.1-mini）・E（gpt-realtime-2.1）を選べるようにした（`App\Services\Voice\VoiceRealtimeService`）。ボタンを押すと、BlogOS が OpenAI から、その場限りの鍵（client_secrets）を受け取ってブラウザに渡し（本当の API キーは渡さない）、ブラウザが OpenAI と直接つながる（WebRTC。`public/js/voice.js`）。会話の設定（指示と話し方・道具（方式 C と同じ）・声・利用者の発言の文字起こし（gpt-4o-mini-transcribe）・話し終わりの判定（semantic_vad））は、鍵を作るときに渡す。会話を開いている間は、ボタンを押し直さずに続けて話せる。もう一度押す・Esc・60秒話しかけない・5分たつ、のどれかで終わり、画面を移る命令は返事が終わってから移る（会話も終わる）。AI が呼んだ道具は、ブラウザが BlogOS に頼んで実行し（`POST /voice/realtime/tool`）、結果を AI に返す。確認つきの操作は、ブラウザが利用者の話し始めるたびに数える発言の番号で、次の発言でだけ確認を受け付ける（方式 C と同じ決まり）。AI の応答ごとに、使用量（音声と文字のトークン数）をブラウザが送り（`POST /voice/realtime/usage`）、料金（config/blogos.php の voice.realtime.prices。2026-10-05 に確認）と文字起こしの費用から計算して voice_turns に残す。会話を始める前に、5分聞いて話し続けた場合の費用で、残高と月の上限を確かめる。音声のモデルの料金を、毎日の公式ページとの照合（料金表 ai_prices）に加えることは、音声の使い方が固まってから行う（今は設定の料金で計算する）。

### 項目59：画面のパネル（2026-10-05）

**D-59-01 画面を移らずに、横から出るパネルに開く** リアルタイム会話（D-58-06）では、画面を移るとページ全体を読み込み直すため、OpenAI との接続が切れて会話が終わり、続けて画面を開くたびにボタンを押し直す必要があった。そこで、声で開く画面と、トップページのリンク・メニューを、画面を移らずに、右から出る「画面のパネル」（`layouts/header` の `#screen-drawer`。中に画面をそのまま入れて表示する）に開くようにした（利用者の案。テーマ切替のようなポップアップは画面の真ん中のアークリアクターを隠すため、横から出るパネルにした）。会話は切れず、AI も直前のやり取りを覚えたまま、続けて別の画面を開ける。パネルの中の画面は、ヘッダーとメニューを出さない（`layouts/head` で、ほかの画面の中に表示されているかを、表示する前に判定する）。パネルの上の帯に「全画面で開く」（パネルの中の今の画面を、ふつうの画面として開く）と「×」を置き、×・Esc・声の「閉じて」（道具 close_screen）・パネルの中のトップページへのリンクで閉じる。パネルの中で、ほかのサイトへのリンクは新しいタブで開く。トップページ以外の画面のリンクは、今までどおり画面を移る（トップページ以外の画面で声で画面を開いた場合も、パネルに開く。トップページを開く命令だけ、返事の後に画面を移る）。方式 C でも同じく、パネルで開ける画面は、返事を待たずに開く。

**D-59-02 見た目** パネルの幅は、パソコン 62%（最大1100px）・タブレット 75%・スマホは全体。上の端はヘッダーとメニューの下にし、ヘッダーのマイクのボタンとメニューを使えるようにする。パネルを開いている間、会話の欄は左下に出す（スマホは重ねる）。ironman のトップページでは、パネルを開いている間、左の空いた所にアークリアクターだけを出し（状態のパネル・お知らせ・入口は隠す）、聞いている・考えている・話している の反応が見えるようにした。パネルの上の帯の左端の点も、声の操作の状態で光る（スマホでアークリアクターが隠れるときの代わり）。

**D-59-03 読み込み直しと、ほかのサイトの中での表示** 同期の終わりに画面を読み込み直す処理（`partials/sync-poll`）は、パネルを開いている間と声の会話中は、終わるまで待つ（会話が切れないように）。BlogOS の画面を、ほかのサイトの中に入れて表示させないよう、すべての画面に `X-Frame-Options: SAMEORIGIN` を付けた（`App\Http\Middleware\SameOriginFrames`。BlogOS 自身のパネルは同じサイトなので表示できる）。

### 項目60：ヘッダーと上の帯の見た目・設定の入口（2026-10-05）

**D-60-01 設定の入口は「管理」** ヘッダーの「BlogOS」の横の「設定」ボタンをやめ、トップページの入口の「管理」（SYSTEM）の先頭に「設定」を移した（`App\Support\DashboardLinks`。利用者の要望）。トップページ・メニューから開くと、画面のパネル（D-59）に開く。声で開く画面の「設定」も、この入口から作る。

**D-60-02 上の帯とポップアップの見た目（ironman）** ヘッダーとメニューバーの間の線をなくし、1つの帯に見せる（帯の下の線と光は、メニューバーの下に引く）。帯・メニューの項目・ブログ切替とテーマ切替のポップアップは、HUD のパネルと同じ、青をうっすら含んだ透ける色にし、後ろをすりガラスのようにぼかす（スクロールして下を通る本文で、文字が読みにくくならないように）。ポップアップの外側の暗さは60%（45%では、ポップアップが目立たなかった。利用者の確認）。blank は、間の線をなくしただけ。

**D-60-03 音声の方式の呼び方** 音声の操作の方式（D-58）を、画面では A・B・C と呼ぶ（検討のときの案の記号 C・D・E のままだと、A・B がないように見えるため。利用者の要望）。A＝これまでの C（順番に処理）、B＝D（リアルタイム会話 mini）、C＝E（同 標準）。保存する値（system_settings の voice.mode・voice_turns.mode）とプログラムの中の記号は c・d・e のまま（データの移し替えをしない）。この文書の D-58 の C・D・E は、検討のときの記号のまま残す。

### 項目61：音声操作のポップアップ（2026-10-05）

**D-61-01 音声操作の設定は、メニューのポップアップ** メニューの「設定」（SETTING）の中、「画面のテーマ」の上に「AI」を加え（押すと横に開く下の階層。スマホは下に開く）、その中の「音声操作」で、テーマ切替・ブログ切替と同じ形の音声操作ポップアップを開くようにした（`resources/views/voice/settings-modal`。`App\Support\MenuItems`。利用者の要望）。中身は、画面「AIの設定」にあった「音声の操作」の欄（説明・有効・方式・声・話し方の指示）を移したもので、「AIの設定」の画面からは消した（音声の設定はブログごとではなく BlogOS 全体の設定のため）。保存した後は開いていた画面に戻り、入力の誤りで戻ったときは、ポップアップを開いたままにして誤りを出す（`data-modal-autoopen`）。ポップアップを閉じる仕組み（×・背景・Esc）は、`class="site-modal"` のポップアップで共通にした。

**D-61-02 説明はツールチップ** 音声操作ポップアップの説明の文（使い方・方式ごとの動きとモデル・費用・声・話し方）は、ポップアップの中に並べず、各項目の横の「?」のツールチップに出す（利用者の要望）。マウスを乗せる・押す（スマホ）・Tab で選ぶと出し、外を押す・Esc・スクロールで消す。ツールチップは、ほかの画面でも使える共通の部品にした（`resources/views/partials/tip`・`public/js/blogos.js`。`data-tip` に説明の文を入れる）。ポップアップの中でも切れないよう、画面に固定した枠に出す。API キーがないときの注意と、入力の誤りは、今までどおり本文に出す。

### 項目62：残高・課金の登録のポップアップ（2026-10-05）

**D-62-01 登録はメニューのポップアップ** 画面「AIの費用と残高」の「登録する」の欄を、メニューの「設定 → AI」の「音声操作」の上に移した（利用者の要望）。「OpenAIの画面で見た残高を登録」と「課金した額を登録」を別の項目にし、それぞれ音声操作と同じ形のポップアップで開く（`resources/views/ai/credits/modals`）。「AIの費用と残高」の画面からは登録の欄を消し、説明の文と、残高のお知らせ（`partials/ai-credit-notice`）のリンクから、同じポップアップを開けるようにした。説明の文は、各項目の「?」のツールチップに出す（D-61-02）。登録した後は開いていた画面に戻り、入力の誤りで戻ったときは、ポップアップを開いたままにして誤りを出す。

**D-62-02 日時の欄の初期値は今** 日時の欄（これまでは「空なら今」）に、ポップアップを開いた時刻（日本時間）を初めから入れる（`data-default-now`。開くたびに入れ直す）。あわせて、日時が今より後かの判定を、入力を日本時間として読んでから行うようにした（以前の検証 before_or_equal:now は入力を UTC として読むため、日本時間の今を9時間先と判断して断っていた。空のまま登録していたため表に出ていなかった）。

---

## 3. 未決定のまま残す事項

| 事項 | 決める時期 |
| --- | --- |
| OpenAI APIの契約と、作業ごとの標準モデルの最終決定（3モデルでの比較試験を含む） | AI実行の切り替え処理の実装時 |
| 画像生成の料金と運用 | 同上 |
| 複数ユーザーと権限 | 必要になった時点 |
| 通知機能のうち、画面表示以外の手段（メール等） | 必要になった時点 |
| WordPressの対応最低バージョン | 実装・検証時 |
| WordPressマルチサイトへの対応 | 別途の要件定義時 |
| カスタム投稿タイプの編集・反映への対応 | 必要になった時点 |
| 保存期間の見直し、削除判定の割合（初期値10%）の調整 | 運用開始後 |
| XServerのcron間隔、PHPの実行時間の上限、DBエンジンのバージョン（CHECK制約の対応を含む） | 実装前に確認 |
| 設定値の保存先（config / DB）| 実装時 |
| 履歴と評価結果の保存期間 | 運用開始後 |

---

## 4. 現在の実装との差異として把握している事項

以下は監査の過程で判明したもので、`BLOGOS_CURRENT_STATUS.md` で正式に記録・分類する。

* WordPressの認証情報を `WordPressApiClient.php`・`config/services.php`・`.env` で管理している（D-03-02 との差異）。
* API確認画面のControllerが `Controllers/Api` にある（D-11-02 との差異）。
* `.env.example` の `DB_CONNECTION` が `sqlite`（D-12-05 との差異）。
* ログイン機能（`AdminUserSeeder`、`LoginController`、`login.blade.php`）は実装中（D-03-01 と同じ方針）。
