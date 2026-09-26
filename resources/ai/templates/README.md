# AI実行テンプレート

BlogOSのAI機能が、AIへの指示文を作るときに使うテンプレート（D-07-05、D-06-07）。BlogOSが実行時に読み込む。

## 1. ファイル

| ファイル | 実行モード |
| --- | --- |
| `seo_analysis.md` | SEO分析 |
| `structure.md` | 構成作成 |
| `quality_diagnosis.md` | 品質診断 |
| `revision.md` | 記事改修 |
| `new_article.md` | 新規記事作成 |

HTML出力のテンプレートは、ブログ別のHTMLのルール（`resources/quality/blogs/{ブログ}/html-rules.md`）ができてから作る。

## 2. 書き方

各ファイルは、冒頭の設定と、`## 指示文` の見出しより後の指示文でできている。

* `**テンプレートバージョン:**` … AI実行記録（`ai_generations.template_version`）に記録する。指示文を変えたら上げる（出力の形式を変えた場合はマイナーバージョン以上）。
* `**品質基準のファイル:**` … 指示文の `{{quality_files}}` に入れる品質基準のファイル（`resources/quality/` からのパス。`{profile}` はブログ別の定義のフォルダ名に置き換える）。AIには品質基準の全文ではなく、必要なファイルだけを渡す（ARCHITECTURE 18-4）。

指示文の `{{名前}}` は、BlogOSが実行時に次の値に置き換える（`App\Services\Ai\PromptBuilder`）。

| 名前 | 値 |
| --- | --- |
| `blog_name` / `blog_home` | ブログの表示名・ホームURL |
| `quality_versions` | 品質基準のバージョン |
| `quality_files` | 上で指定した品質基準のファイルの内容 |
| `required_table` / `scoring_table` | 必須条件と、採点の対象の項目（キー・配点・判定者）。対象外の項目は含まない |
| `article_info` | 記事の情報（種類・状態・URL・記事種類・検索意図・キーワード・この記事へのリンクの数など） |
| `article_content` | 記事（または編集案）の本文 |
| `revision_scope` | 改修範囲とその説明 |
| `shortfalls` | 最新の評価で ○ でなかった項目と理由 |
| `parameters` | 人が画面で入力した情報（キーワード・補足・実体験など） |
| `article_list` | ブログの公開済みの記事の一覧（タイトルとURL。内部リンクの候補） |

## 3. 変更履歴

| 日付 | 内容 |
| --- | --- |
| 2026-09-26 | 初版（5つの実行モード） |
| 2026-09-26 | メタディスクリプション（AIOSEO）を追加。記事改修・新規記事作成 1.1.0（出力に「=== メタディスクリプション ===」を追加）、品質診断 1.0.1（判定の根拠を追記）（D-23-04） |
