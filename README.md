# BlogOS

WordPress のブログを、記事の品質・SEO・収益の面から管理する、個人用の Web アプリ（Laravel）。

* WordPress の内容を毎日同期し、BlogOS で作った編集案を、人の承認を経て WordPress に反映する。
* Google（GA4・Search Console・AdSense）のデータを取り込み、記事ごとに確認する。
* 品質基準に沿って、AI（OpenAI）が記事を評価・改修・新規作成する。AI・BlogOS が、人の承認なしに WordPress を変えることはない。

## 文書

| 文書 | 内容 |
| --- | --- |
| [docs/BLOGOS_REQUIREMENTS.md](docs/BLOGOS_REQUIREMENTS.md) | 要件定義 |
| [docs/BLOGOS_ARCHITECTURE.md](docs/BLOGOS_ARCHITECTURE.md) | 設計 |
| [docs/BLOGOS_DATABASE.md](docs/BLOGOS_DATABASE.md) | DB 設計 |
| [docs/BLOGOS_WORDPRESS_API.md](docs/BLOGOS_WORDPRESS_API.md) | WordPress REST API の使い方 |
| [docs/BLOGOS_DEVELOPMENT_RULES.md](docs/BLOGOS_DEVELOPMENT_RULES.md) | 開発のルール |
| [docs/BLOGOS_QUALITY_STANDARD.md](docs/BLOGOS_QUALITY_STANDARD.md) | 品質基準（本体は `resources/quality/`） |
| [docs/BLOGOS_DECISIONS.md](docs/BLOGOS_DECISIONS.md) | 決定の記録 |
| [docs/BLOGOS_IMPLEMENTATION_PLAN.md](docs/BLOGOS_IMPLEMENTATION_PLAN.md) | 実装計画と進捗 |
| [docs/BLOGOS_CURRENT_STATUS.md](docs/BLOGOS_CURRENT_STATUS.md) | 現在の実装状況 |
| [docs/BLOGOS_DEPLOYMENT.md](docs/BLOGOS_DEPLOYMENT.md) | 本番（XServer）の配置と稼働の開始 |

## 動作環境

* PHP 8.3 以上、MySQL 8
* 本番は XServer（共用サーバー）。定期実行と Queue は cron で起動する（[docs/BLOGOS_DEPLOYMENT.md](docs/BLOGOS_DEPLOYMENT.md)）

## 開発

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test   # テスト専用の MySQL の DB（blogos_testing）を使う
```

`.env` には、API キー・パスワードなどの秘密の値が入る。コミットしない。

## ライセンス

Laravel フレームワーク（[MIT license](https://opensource.org/licenses/MIT)）を使用。
