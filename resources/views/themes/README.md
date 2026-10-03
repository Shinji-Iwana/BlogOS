# 画面のテーマ（D-16-01・D-49）

BlogOS の画面の見た目を、テーマとして切り替える。使うテーマは画面「設定」→「画面のテーマ」で選ぶ（`system_settings` の `theme`。選んでいなければ `config/themes.php` の `default`）。

テーマは見た目だけを担当する。表示するデータ・業務処理・ルートは、テーマによって変えない（どのテーマでも同じ Controller が同じデータを渡す）。

## テーマの一覧

| テーマ | 内容 | 状態 |
| --- | --- | --- |
| `blank` | 装飾なし。機能を作って、とりあえず出して確かめるための見た目 | 使用できる |
| `ironman` | 映画「アイアンマン」の世界観（アークリアクター・HUD） | 制作中 |

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

public/themes/{テーマ名}/                テーマの CSS・JavaScript・画像（全画面で読み込む）
├─ css/style.css                         入口（ここから他の CSS を読み込む）
├─ js/script.js                          入口（ここから他の JS を読み込む）
└─ images/
```

## 画面の上書きの仕組み

`App\Http\Middleware\ApplyTheme` が、`resources/views/themes/{使っているテーマ}/` を、View を探す場所の先頭に加える。

* テーマに共通の画面と同じ名前の View があれば、そのテーマのときだけそちらを使う（例：ironman の `dashboard/index.blade.php` は、トップページにアークリアクターを加える）。
* なければ、共通の画面をそのまま使う。`blank` は何も上書きしない。
* テーマだけの部品は、`themes.{テーマ名}.components.xxx` のように、テーマ名を含めて読み込む（他のテーマの部品と名前がぶつからないようにする）。
* 上書きした View は、共通の画面の変更（お知らせの追加など）が自動では入らない。中身は共通の部品（例：`dashboard.content`）を `@include` して、並べ方・飾りだけを変える。

## テーマを追加する手順

1. `public/themes/{テーマ名}/css/style.css` と `js/script.js` を作る（空でもよい）。
2. 画面を作り替える場合だけ、`resources/views/themes/{テーマ名}/` に共通の画面と同じ名前の View を置く。
3. `resources/views/themes/{テーマ名}/README.md` に、テーマの考え方を書く。
4. `config/themes.php` の `themes` に1行を追加する（`status` は、作っている間は `wip`、使えるようになったら `ready`）。
5. 本番では `php artisan config:cache` と `php artisan view:cache` をやり直す。
