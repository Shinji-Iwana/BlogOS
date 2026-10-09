#!/bin/sh
# Queue の処理（XServer のサーバーパネルの Cron から毎分起動する。BLOGOS_DEPLOYMENT.md 5章）。
#
# Cron の画面のコマンドの欄は、カンマ（,）などの文字を受け付けないことがあるため、コマンドはこのファイルに書き、
# Cron には「/bin/sh <BlogOS のフォルダ>/scripts/xserver/queue-worker.sh」だけを登録する。
#
# ・flock：同時に1つだけ動かす（すでに動いていれば何もしない）
# ・--queue=default,pagespeed：同期・AI の処理（default）を先に、PageSpeed Insights の測定（pagespeed）を後に処理する（D-78-03）
# ・--stop-when-empty：たまった処理を片付けたら終わる。--max-time：1つの起動が処理を続ける上限の秒数

BLOGOS_DIR=$(cd "$(dirname "$0")/../.." && pwd)

exec /usr/bin/flock -n "$BLOGOS_DIR/storage/framework/queue-worker.lock" \
    /usr/bin/php8.4 "$BLOGOS_DIR/artisan" queue:work --queue=default,pagespeed --stop-when-empty --max-time=3300 \
    >> /dev/null 2>&1