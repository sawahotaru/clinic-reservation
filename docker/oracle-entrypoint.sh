#!/bin/sh
# clinic-reservation (Oracle/本番) の起動前処理:
#  - data/ ボリュームを Apache 実行ユーザー(www-data)所有にする（SQLite書込のため）
#  - .htpasswd は 644 でないと Apache(group www)が読めず 500 になるため強制
#  - holidays.csv が未配置なら初期シードを置く
set -e
DATA=/var/www/html/clinic-reservation/data
mkdir -p "$DATA"

# holidays.csv の初期シード（永続ボリュームが空の初回のみ）
if [ ! -f "$DATA/holidays.csv" ] && [ -f /opt/seed/holidays.csv ]; then
  cp /opt/seed/holidays.csv "$DATA/holidays.csv"
fi

chown -R www-data:www-data "$DATA" 2>/dev/null || true
[ -f "$DATA/.htpasswd" ] && chmod 644 "$DATA/.htpasswd" || true

exec apache2-foreground
