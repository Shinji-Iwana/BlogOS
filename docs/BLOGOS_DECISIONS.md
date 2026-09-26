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
