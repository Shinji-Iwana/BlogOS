# 画面のテーマ（D-16-01・D-49）

BlogOS の画面の見た目を、テーマとして切り替える。使うテーマは画面「設定」→「画面のテーマ」で選ぶ（`system_settings` の `theme`。選んでいなければ `config/themes.php` の `default`）。

テーマは見た目だけを担当する。表示するデータ・業務処理・ルートは、テーマによって変えない（どのテーマでも同じ Controller が同じデータを渡す）。

## テーマの一覧

| テーマ | 内容 | 状態 |
| --- | --- | --- |
| `blank` | 装飾なし。機能を作って、とりあえず出して確かめるための見た目 | 使用できる |
| `ironman` | 映画「アイアンマン」の世界観（アークリアクター・HUD） | 使用できる（2026-10-04） |

## ファイルの置き場所

```
config/themes.php                        テーマの登録（名前・説明・状態）と既定のテーマ

resources/views/                         共通の画面（どのテーマでも、上書きしない限りこれを使う）
├─ layouts/app.blade.php                 全画面の枠（テーマの CSS・JS を読み込む）
├─ layouts/header.blade.php              共通のヘッダー
├─ dashboard/index.blade.php             トップページ
├─ dashboard/content.blade.php           トップページの中身（お知らせと各画面への入口）
└─ themes/{テーマ名}/                    テーマの画面（共通の画面の上書きと、テーマだけの部品）
   ├─ README.md                          テーマの説明
   ├─ （共通の画面と同じ名前の View）    例：dashboard/index.blade.php、layouts/app.blade.php
   └─ components/                        テーマだけの部品（例：ironman のアークリアクター）

public/css/blogos.css                    共通の画面の見た目（どのテーマでも最初に読み込む。blank はこれだけ）
public/themes/{テーマ名}/                テーマの CSS・JavaScript・画像（全画面で、blogos.css の後に読み込む）
├─ css/style.css                         入口（読み込む CSS を @import で順に書くだけ。BlogOS がこれを読み取り、
│                                         1つずつ更新日時を付けて読み込む。読み込まれる側では @import を使わない）
├─ js/script.js                          入口（ここから他の JS を読み込む）
└─ images/
```

## 画面の上書きの仕組み

`App\Http\Middleware\ApplyTheme` が、`resources/views/themes/{使っているテーマ}/` を、View を探す場所の先頭に加える。

* テーマに共通の画面と同じ名前の View があれば、そのテーマのときだけそちらを使う（例：ironman の `dashboard/index.blade.php` は、トップページにアークリアクターを加える）。
* なければ、共通の画面をそのまま使う。`blank` は何も上書きしない。
* テーマだけの部品は、`themes.{テーマ名}.components.xxx` のように、テーマ名を含めて読み込む（他のテーマの部品と名前がぶつからないようにする）。
* 上書きした View は、共通の画面の変更（お知らせの追加など）が自動では入らない。中身は共通の部品（例：`dashboard.content`）を `@include` して、並べ方・飾りだけを変える。

## 共通の画面の見た目の書き方

* 色・背景・枠線は `style="…"` に書かず、`public/css/blogos.css` の意味ごとの class を使う（テーマの CSS で変えられるようにするため。直接の指定はテーマの CSS より優先される）。
  * 文字：`text-muted`（補足）・`text-dim`・`text-faint`・`text-error`（エラー・要対応）・`text-warn`（注意）・`text-ok`（成功）
  * 表の行：`row-current`（選択中）・`row-attention`（確認が必要）・`row-danger`（問題が残っている）・`row-error`・`row-group`
  * 箱：`bordered`・`bg-surface`・`bg-subtle`・`code-block`・`mono`
  * 表：`<table class="data">`（余白の狭い表は `data data-compact`）
    * 表は画面の幅に収め、収まらなければ `public/js/blogos.js` が行ごとのカードの形（`table.data.stacked`）にする（D-50）。列の見出しは `<thead>` の最後の行（または th だけの最初の行）から取る。テーマの CSS では `table.data.stacked` の見た目も決める
  * 点数の棒：`bar-track`・`bar-good`・`bar-mid`・`bar-bad`、差分：`diff-added`・`diff-removed`・`diff-skip`
  * 画面の区切り：`<section class="panel">`（`<fieldset>`・`<form>` にも付けてよい）。最初の h2・legend が見出し。`data-code="EDITOR"` のような英字の札を付けてよい
  * ボタン：何も付けない＝主な操作、`btn-secondary`＝補助の操作、`btn-danger`＝取り消せない・壊す操作。主な操作へのリンクは `<a class="button-link">`
* 幅・余白・文字の寄せなど、その画面だけの配置は `style="…"` に書いてよい。
* 新しい意味の見た目が必要になったら、`blogos.css` に class を加え、各テーマの CSS でも見た目を決める。

## テーマを追加する手順

1. `public/themes/{テーマ名}/css/style.css` と `js/script.js` を作る（空でもよい）。
2. 画面を作り替える場合だけ、`resources/views/themes/{テーマ名}/` に共通の画面と同じ名前の View を置く。
3. `resources/views/themes/{テーマ名}/README.md` に、テーマの考え方を書く。
4. `config/themes.php` の `themes` に1行を追加する（`status` は、作っている間は `wip`、使えるようになったら `ready`）。
5. 本番では `php artisan config:cache` と `php artisan view:cache` をやり直す。
