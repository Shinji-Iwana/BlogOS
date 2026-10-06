# ironman テーマ

映画「アイアンマン」の世界観の見た目。トニー・スタークのラボの画面のように、暗い背景に青白く光るアークリアクターと HUD（ヘッドアップディスプレイ）の線・文字で、BlogOS の画面を表す。

## 決まり（D-49-04）

* 色：基本は暗い背景に HUD の青白い光（cyan）。スーツの赤と金は、ボタン・注意・要対応だけに使う。色は `css/tokens.css` の変数で決め、他の CSS では色を直接書かない。
* 動き：大きな動き（回転・脈動）は、トップページのアークリアクターだけ。他の画面は、表示のときと、マウスを乗せたときに少し光る程度。OS の「アニメーションを減らす」を選んでいる場合は、すべて止める（`css/accessibility.css`）。
* 書体：本文は Noto Sans JP、見出し・表の見出し・ボタンは Rajdhani、ロゴは Orbitron（Google Fonts）。
* 共通の画面は作り替えず、`public/css/blogos.css` の class（`text-error`・`table.data` など）を、ironman の CSS で上書きして変える。

## ファイル

| 場所 | 内容 |
| --- | --- |
| `dashboard/index.blade.php` | トップページ。SYSTEM STATUS の帯、アークリアクターと左右の状態のパネル、お知らせ（共通の `dashboard.notices`）、種類ごとの入口のパネル |
| `components/status-panel.blade.php` | 状態のパネル（`DashboardStatusService` の1つ） |
| `auth/login.blade.php` | ログイン画面。中央にアークリアクター・BlogOS の名前・入力欄のパネル（`css/components/login.css`） |
| `components/reactor.blade.php` | アークリアクター（SVG。鋼の外枠・10個のコイル・三角のコア・HUD の円）。`$reactorState`（normal／warning／critical）と `$reactorBusy` で色と動きが変わる。形は `config/themes.php` の `reactor_variant`（1・2） |
| `components/reactor-coils.blade.php` | アークリアクターの10個のコイル（外側と、形 2 のコアの背面で使う） |
| `public/themes/ironman/css/style.css` | 入口。読み込む CSS と順番だけを書く |
| `css/tokens.css` | 色・書体・光の変数 |
| `css/base.css`・`background.css`・`layout.css` | 文字・リンク・見出し、画面の背景（HUD の格子）、本文の余白とアークリアクターの領域 |
| `css/components/` | 部品：`header`（HUD のバー・ブログ切替）、`text`（意味ごとの文字の色・お知らせ）、`table`（表・行の強調）、`form`（入力欄・ボタン）、`box`（箱・折りたたみ・コード・点数の棒・差分）、`dashboard`（トップページの HUD のパネル・並べ方） |
| `css/reactor/reactor.css` | アークリアクターの大きさと、状態ごとの光の色（変数） |
| `css/animation/` | 動き（`reactor.css`：光の脈打ちと HUD の円の回転、`page.css`：ページを開いたときの表示） |
| `css/accessibility.css` | 動きを減らす設定への対応（最後に読み込む） |
| `public/themes/ironman/js/` | `script.js` が入口（今は読み込むものはない）。`dashboard/connectors.js`：トップページで、アークリアクターから状態のパネルへ線を引く（パソコンの幅だけ。トップページの View から読み込む）。`dashboard/panel-flow.js`：トップページのパネルの枠に、マウスを乗せたときに流れる電気の線を置く |
| `public/themes/ironman/images/arc-reactor.png` | アークリアクターの完成のイメージ（参考画像。画面では使わない） |

## 進み具合

| 段 | 内容 | 状態 |
| --- | --- | --- |
| T1 | 共通の画面をテーマで変えられる形にする（`blogos.css`） | 完了 |
| T2 | 全画面の基本の見た目（色・文字・ヘッダー・表・入力・ボタン・箱） | 完了 |
| T3-1 | アークリアクターの作り直し（SVG。機械感・大きさ・状態の色。形 2 を採用） | 完了 |
| T3-2 | トップページの並べ方・状態のパネル・入口のパネル | 完了 |
| T3-3 | 状態とアークリアクターの色のつなぎ込み | 完了 |
| T4 | 全画面の仕上げ（画面の区切り `.panel` とボタンの種類） | 完了 |
| T5 | スマートフォン・読みやすさ・負荷 | 完了 |
