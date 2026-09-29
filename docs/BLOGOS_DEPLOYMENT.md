# BlogOS 本番の配置と稼働の開始（XServer）

**作成日:** 2026-09-29

## 1. 本書について

BlogOS を XServer（共用サーバー）に配置し、本番で稼働を始めるための手順。作業は利用者が行う（`BLOGOS_IMPLEMENTATION_PLAN.md` 5章の 3）。

* 実行基盤の設計は `BLOGOS_ARCHITECTURE.md` 28章、リリースの確認事項は `BLOGOS_DEVELOPMENT_RULES.md` 19章。
* パスワード・API キーなどの値は、本書にもリポジトリにも書かない（リポジトリは公開）。
* 本書の `<…>` は、XServer の環境に合わせて置き換える（例：`<BlogOS のフォルダ>`、`<PHP>`）。

---

## 2. 本番のデータ：初めから始める（2026-09-29 利用者の判断）

ローカルの開発用 DB のデータ（検証のときの AI の実行記録・評価・編集案・画像など）は、本番に移さない。本番で Migration を実行し、ブログの登録・同期・Google の接続・提携先の状態などを、本番で設定し直す（6章）。本番できちんと動くかを、初めから確かめる目的も兼ねる。

* 教材は、同期のたびに記事のリンクから検出し直される（D-30）。教材の情報の調査は、本番での利用者の作業で行う。
* 作業の前に、本番の DB をバックアップする。

---

## 3. 配置

### 3-1. ローカルでの確認

1. テストがすべて成功することを確かめる（`php artisan test`）。
2. コミットして、GitHub に push する。

### 3-2. XServer での作業

```bash
cd <BlogOS のフォルダ>
git pull
<PHP> <composer のパス> install --no-dev --optimize-autoloader
```

* XServer の SSH の `php`・`composer` は、古い PHP（5.4）と Composer 1 になる。PHP は `/usr/bin/php8.4`（または `php8.3`）を明示し、Composer 2 は `~/bin/composer` に入れて `/usr/bin/php8.4 ~/bin/composer` で使う（SSH の作業では、`~/.bashrc` に `alias php='/usr/bin/php8.4'`・`alias composer='/usr/bin/php8.4 ~/bin/composer'` を加えると楽）。cron では alias が効かないため、必ずフルパスで書く。
* 画面の CSS は `public/themes/` にあり、Vite のビルドは要らない。
* `storage/` と `bootstrap/cache/` に書き込めることを確かめる。`storage/app/private/`（画像のファイル）は、Git の管理外。配置で消さない。

### 3-3. `.env`

`.env.example` を基に、次を設定する。

| 項目 | 値 |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false`（エラーの内容を画面に出さない） |
| `APP_URL` | 本番の URL（`https://…`） |
| `APP_KEY` | 本番の値（未設定なら `php artisan key:generate` で作る。ローカルとは別の値でよい）。**本番で使い始めた後は変えない**（変えると、暗号化した認証情報を読めなくなる）。値は、`.env` とは別の安全な場所にも控える |
| `DB_*` | 本番の MySQL |
| `ADMIN_EMAIL`・`ADMIN_PASSWORD` | 管理者（`AdminUserSeeder` を実行する場合） |
| `OPENAI_API_KEY` | OpenAI の API キー。画像の作成に使うため、キーの権限に Images を含める |
| `BLOGOS_AI_CREDIT_WARNING_USD` | 任意。残高の見込みがこの額を下回ったら知らせる |
| `BLOGOS_AI_MONTHLY_BUDGET_USD` | 任意。月の支出の上限 |
| `RAKUTEN_APPLICATION_ID` | 任意。教材の調査で楽天ブックスの情報を使う場合 |
| `GOOGLE_OAUTH_CLIENT_ID`・`GOOGLE_OAUTH_CLIENT_SECRET` | Google Cloud の OAuth クライアント |
| `GOOGLE_OAUTH_REDIRECT_URI` | `https://<本番のドメイン>/google/oauth/callback`（Google Cloud にも同じ URI を登録する。4章） |
| `HTTP_CA_BUNDLE` | 空（ローカル専用。D-18-06） |
| `QUEUE_CONNECTION`・`CACHE_STORE`・`SESSION_DRIVER` | `database` |
| `LOG_LEVEL` | `warning` 程度 |

使わなくなった項目は削除する：`WP_APP_USER`・`WP_APP_PASSWORD`（段階2から、ブログごとの認証情報を DB で使う）、`GA4_PROPERTY_ID`・`GSC_SITE_URL`・`GOOGLE_ADSENSE_*`（`GOOGLE_OAUTH_*` を設定した後）。試作のファイル `storage/app/google/` があれば削除する。

### 3-4. DB と最適化

```bash
<PHP> artisan down
<PHP> artisan migrate --force
<PHP> artisan config:cache
<PHP> artisan route:cache
<PHP> artisan view:cache
<PHP> artisan queue:restart
<PHP> artisan up
```

* 管理者がまだいない場合は、`<PHP> artisan db:seed --class=AdminUserSeeder --force`（本番には、段階0の前から管理者がいる。D-17-05）。Migration で、初期状態の Test User は削除される（D-17-02）。
* `migrate --force` の前に、本番の DB をバックアップする。

---

## 4. Google Cloud

OAuth クライアントの「承認済みのリダイレクト URI」に、`https://<本番のドメイン>/google/oauth/callback` を加える（ローカルの URI は、ローカルでも使うなら残す）。

* `.env` の `GOOGLE_OAUTH_REDIRECT_URI` と、1文字も違わないこと（`https`・末尾の `/` なし）。`.env` を変えたら `config:cache` をやり直す。
* OAuth 同意画面の公開ステータスが「テスト」のままだと、Google の決まりで、接続（更新トークン）が7日で切れる。毎日の自動の取得を続けるには「本番環境」にする（自分のアカウントだけで使うため、Google の審査は受けずに使える。接続のときに「確認されていないアプリ」の警告が出たら、「詳細」から進む）。

---

## 5. cron（XServer のサーバーパネル）

共用サーバーでは処理を常駐できないため、cron で起動する（ARCHITECTURE 28章）。XServer のサーバーパネルの「Cron設定」で、次の2つを登録する（分・時間・日・月・曜日はすべて `*`）。

```cron
# 定期実行（毎分）
/usr/bin/php8.4 <BlogOS のフォルダ>/artisan schedule:run >> /dev/null 2>&1
# Queue の処理（毎分起動。すでに動いていれば何もしない。たまった処理を片付けたら終わる）
/usr/bin/flock -n <BlogOS のフォルダ>/storage/framework/queue-worker.lock /usr/bin/php8.4 <BlogOS のフォルダ>/artisan queue:work --stop-when-empty --max-time=3300 >> /dev/null 2>&1
```

* cron では、SSH の `alias` や `PATH` の設定は効かない。PHP・artisan は、必ずフルパスで書く（例：`<BlogOS のフォルダ>` は `/home/<アカウント名>/BlogOS`）。
* Queue の処理は、`flock` で同時に1つだけ動かす。AI のまとめて実行は1記事ずつ順に処理する設計のため（D-25）。共用サーバーで、処理が何本も同時に動くのも防ぐ。
* `--max-time` は、1つの起動が処理を続ける上限の秒数。上限を過ぎると、処理中の Job を終えてから止まり、次の分の起動が続きを処理する。
* エラーは `storage/logs/laravel.log` に記録される（cron の出力は捨てる）。
* cron が動いているかは、画面の「今すぐ同期」を押して、数分以内に同期が終わるかで確かめられる（Queue の処理の確認）。定期実行は、画面「定期実行」の実行の記録で確かめる（何時に何を実行するか・時刻の変更・今すぐ実行も、この画面で行う。D-44）。

**配置のたびに** `/usr/bin/php8.4 artisan queue:restart` を実行する（動いている処理を、次の Job から新しいコードにする）。

---

## 6. 稼働の開始の後の初期作業（本番の画面で）

ローカルで設定したものを、本番で設定し直す。

1. ログインする。トップページにエラーがないことを確かめる。
2. ブログを登録する（si-note。WordPress のアプリケーションパスワードは、BlogOS の画面で登録する）。
3. 「今すぐ同期」を押し、同期が終わること（同期の問題 0件）を確かめる。翌日、3:00 の自動の同期が動いたことを確かめる（cron の確認）。
4. Google 連携：接続し、対応先（GA4・Search Console・AdSense）を設定して、手動で取得する。
5. 「AIの費用と残高」に、OpenAI の画面で見た残高を登録する。
6. AI の設定（自動の再評価・教材の定期チェックを使うか）を確かめる。
7. 教材の提携先（プログラム）の状態を設定し直す（「教材 → 提携先」の画面。同期で記事のリンクから検出されたプログラムは「未確認」になる。検出されないプログラムは追加する）。ローカルで設定していた状態（2026-09-29 時点）：

    | ASP | プログラム | 状態 |
    | --- | --- | --- |
    | もしも | Amazon.co.jp（p_id 170） | 提携中 |
    | もしも | 楽天市場（p_id 54） | 提携中 |
    | もしも | zero to one（p_id 5256） | 提携中 |
    | もしも | デイトラ（p_id 3554） | 提携中 |
    | もしも | 侍エンジニア（p_id 1421） | 提携中 |
    | もしも | CodeCamp（p_id 936） | 申請中 |
    | もしも | SAMURAI TERAKOYA（p_id 3614） | 否認 |
    | もしも | DMM WEBCAMP（p_id 1000） | 提携終了 |
    | Udemy | Udemy | 提携中 |

8. 必要なら、カテゴリごとのアイキャッチを登録する（画像の画面。ローカルでは未登録）。
9. その後、本番での利用者の作業（教材の一括調査、インデックス未登録の記事・全記事の改修、記事の評価と確認）に進む。

---

## 7. 更新履歴

| 日付 | 内容 |
| --- | --- |
| 2026-09-29 | 初版。本番のデータは初めから始める（利用者の判断）と決めたため、2章・6章をそれに合わせた |
