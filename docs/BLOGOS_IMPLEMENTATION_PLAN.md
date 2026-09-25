# BlogOS 実装計画

**作成日:** 2026-09-26
**最終更新日:** 2026-09-26

## 1. 本書の目的

設計書（v2.0.0）と現在の実装（`BLOGOS_CURRENT_STATUS.md`）の差を、どの順で埋めるかを定める。

* 仕様は設計書で定義する。本書は作業の順序・範囲・完了条件だけを扱う。
* 各段階の完了時に、本書の「進捗」を更新する。

## 2. 前提

| 項目 | 内容 |
| --- | --- |
| 期間 | 開発支援AI（Claude Code Pro）を使える約1か月（〜2026-10-23頃）。毎日作業する。主要機能が間に合わない場合は、契約を継続して作り込む（D-17-04） |
| 進め方 | 既存コードを手直しするより、設計に沿った土台を作り、その上に機能を積み上げる。置き換えた旧コードは、その段階で削除する |
| 開発の順序 | 要件定義書 11章の順：基盤 → コンテンツ管理 → 分析（Google）→ 品質評価・AI（D-17-04） |
| 確認 | 段階ごとに、MySQLでテストを行い、利用者の確認を得てから次の段階に進む |
| XServer | 配置済み。MySQLも用意済み。Seederはローカルと同じで、`blogs`・`blog_histories` のMigrationは動作確認済み。ローカルと異なる部分がある（D-17-05） |

## 3. 段階

### 段階0：セキュリティ（2026-09-26 完了）

| 作業 | 状態 |
| --- | --- |
| 管理者の認証情報をSeederから除き、`.env`（`ADMIN_EMAIL`・`ADMIN_PASSWORD`）から読む（D-17-01） | 完了 |
| `DatabaseSeeder` から Test User の作成を削除（D-17-02） | 完了 |
| 既存の Test User を削除するMigration（ローカルで実行済み。XServerでは次回の `migrate` で削除される） | 完了 |
| ログイン履歴（`login_histories`）の記録（D-17-03） | 完了（確認画面は段階1） |
| 管理者のパスワードの変更 | 当面行わない（利用者の判断） |

**XServerで必要な作業（利用者）**

1. XServerの `.env` に `ADMIN_EMAIL`・`ADMIN_PASSWORD` を追加する（`AdminUserSeeder` を実行する場合に必要）。
2. 変更をXServerに配置し、`php artisan migrate --force` を実行する（Test User の削除と `login_histories` の作成）。

### 段階1：土台の整備（目安1〜2日）

* 不具合 P2〜P6 を解消し、ログインと画面が動く状態に戻す
* 0バイトのファイル37個、存在しないクラスの `use` を削除する
* `.env.example`・`phpunit.xml` をMySQL（テスト専用DB）にそろえる
* `app/Enums`・`app/Clients` を用意する
* ログの出力、遅延読み込みの検出（`preventLazyLoading`）を入れる
* ログイン履歴の確認画面

**完了条件**：ログイン後の全画面がエラーなく表示される。テストがMySQLで実行できる。

### 段階2：ブログと認証情報（目安2〜3日）

* `blogs` の作り直し（`home`・`display_name`・`quality_profile`・`is_selected`・`archived_at`）、`blog_settings`（＋履歴）、`blog_credentials`（暗号化）
* ブログ登録の手順（WORDPRESS_API 29章）、認証情報の登録と接続確認
* ブログごとの認証情報を使う WordPress API Client（`app/Clients/WordPress`）
* ブログ切り替え時のIDの照合（D-02-05）
* 履歴の共通の構造（変更元のEnum、`change_set_id`、`sync_run_id` 等）

**完了条件**：ブログを登録・切り替えでき、ブログごとの認証情報でAPIに接続できる。

### 段階3：同期の仕組み（目安4〜6日）

* `sync_runs`・`sync_run_resources`・`sync_issues`、ブログ単位のロック、Queue・cron、「今すぐ同期」
* 2段階の差分判定、削除の検知と安全策
* 同期の対象：設定、ステータス・投稿タイプ・タクソノミーの定義、投稿者、カテゴリ、タグ、メディア、固定ページ、投稿
* ダッシュボードでの通知
* API確認画面（`WordPressApi`）とDB確認画面（`Database`）を新しい構造で作り直す

**完了条件**：毎日の同期と手動同期で、WordPressの内容がDBに取り込まれ、差分・削除・問題が記録・通知される。XServerのcronで動作する。

### 段階4：記事管理と反映（目安5〜7日）

* 編集案、反映記録、競合の確認と解消、回復処理
* WordPress側の拡張（`Theme-SI-Original` の `inc/blogos-connector.php`）
* 記事の管理情報・キーワード・関係、内部リンク・本文中のメディアの抽出
* カスタム投稿タイプ・カスタムタクソノミーの同期と閲覧

**完了条件**：BlogOSで編集した記事を、承認を経てWordPressへ反映でき、失敗時に回復できる。

### 段階5：分析（Google連携の作り直し）

* Googleの認証情報のDB保存、ブログごとの対応先
* GA4・Search Console・AdSense の取得と、記事への対応付け
* Google指標のテーブル設計（DATABASE 12章の未決定事項を決める）

### 段階6：品質評価・BlogOSのAI機能

* 品質基準の読み込み、評価と人による確定
* AIの手動実行、AI実行テンプレート、生成方法の記録
* （その後）AIのAPI実行への切り替え（OpenAI APIの契約時の注意点を確認する）

### 期間の後に行うもの

* ironman テーマの作り込み（D-16-01）
* si-note の HTMLのルール（`html-rules.md`）

## 4. 進捗

| 段階 | 状態 | 完了日 |
| --- | --- | --- |
| 0 | 完了（XServerでの作業は利用者） | 2026-09-26 |
| 1 | 未着手 | |
| 2 | 未着手 | |
| 3 | 未着手 | |
| 4 | 未着手 | |
| 5 | 未着手 | |
| 6 | 未着手 | |
