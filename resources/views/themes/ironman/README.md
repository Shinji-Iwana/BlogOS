# ironman テーマ（制作中）

映画「アイアンマン」の世界観の見た目。トニー・スタークのラボの画面のように、暗い背景に青白く光るアークリアクターと HUD（ヘッドアップディスプレイ）の線・文字で、BlogOS の画面を表す。

## 今あるもの

| 場所 | 内容 |
| --- | --- |
| `dashboard/index.blade.php` | トップページ。アークリアクターを加え、中身は共通の `dashboard.content` |
| `components/reactor.blade.php` | アークリアクター（背景・リング・機械部品・コア・光の層） |
| `public/themes/ironman/css/` | `style.css` が入口。base・layout・背景・dashboard・reactor・animation に分けている |
| `public/themes/ironman/js/` | `script.js` が入口。`reactor/energy.js` を読み込む |
| `public/themes/ironman/images/` | アークリアクターの画像 |

## 作り込みで決めること

* 全画面の枠（`layouts/app.blade.php`・ヘッダー）を ironman 用に上書きするか、CSS だけで変えるか。
* 共通の画面の見た目は、`public/css/blogos.css` の class（`text-error`・`table.data` など）を、ironman の CSS で上書きして変える（T1 で class に置き換え済み）。
* 一覧・表・入力の部品（表、ボタン、お知らせ）の見た目。
