#!/bin/sh
# clinic-reservation (Oracle/本番) の起動前処理:
#  - data/ ボリュームを Apache 実行ユーザー(www-data)所有にする（SQLite書込のため）
#  - .htpasswd は 644 でないと Apache(group www)が読めず 500 になるため強制
#  - holidays.csv が未配置なら初期シードを置く
#  - 来院前リマインダーの定期実行を起動する（下記）
set -e
APP=/var/www/html/clinic-reservation
DATA="$APP/data"
mkdir -p "$DATA"

# holidays.csv の初期シード（永続ボリュームが空の初回のみ）
if [ ! -f "$DATA/holidays.csv" ] && [ -f /opt/seed/holidays.csv ]; then
  cp /opt/seed/holidays.csv "$DATA/holidays.csv"
fi

chown -R www-data:www-data "$DATA" 2>/dev/null || true
[ -f "$DATA/.htpasswd" ] && chmod 644 "$DATA/.htpasswd" || true

# ---- 来院前リマインダーの定期実行 -------------------------------------------
# 【なぜ要るか】
# これまで本番には定期実行が無く、公開ページへのアクセス契機（remindersTick）だけで
# 送られていた。実際には uptime 監視が15分ごとに `/clinic-reservation/` を叩くので
# 動いてはいたが、それは**監視の副作用**であって、監視を止めたら通知も止まる。
# 依存してよい関係ではない。
#
# 【なぜ専用コンテナや host cron ではないか】
#  - 専用コンテナ: 同じイメージ・同じボリュームが要るうえ、スコープデプロイ
#    （`up -d --build clinic`）は clinic しか作り直さないので、片方だけ古い版で
#    動き続ける状態を作りやすい。1VM(1GB) にサービスを1つ増やす価値もない。
#  - host cron: git に残らず、デプロイでも管理されない。再構築時に必ず忘れる。
#  - docker socket を渡す方式: 通知1つのためにコンテナ脱出の足場を置くことになる。
# → イメージの中に閉じるのが、この構成では一番壊れにくい。
#
# 【www-data で実行する理由】
# root で回すと data/ 配下のファイルが root 所有になり、以後 Web 側が書けなくなる。
# しかも書き込みは @ 抑制なので**無言で失敗する**（B3 の検証時に実際に踏んだ）。
SWEEP="${REMINDER_SWEEP_SECONDS:-600}"
case "$SWEEP" in
  ''|*[!0-9]*) SWEEP=0 ;;   # 数値でなければ無効扱い（誤設定で毎秒回るより、止まるほうがまし）
esac

if [ "$SWEEP" -gt 0 ]; then
  (
    # Apache の起動を邪魔しない。初回は少し待ってから。
    sleep 30
    while true; do
      # --quiet: 送るものが無ければ何も言わない。10分おきに「対象0件」と言われ続けると
      #          ログが読めなくなり、本当に見たい行（失敗）が埋もれる。
      su -s /bin/sh www-data -c "php $APP/bin/send-reminders.php --quiet" 2>&1 || true
      sleep "$SWEEP"
    done
  ) &
  echo "[entrypoint] reminder sweep: every ${SWEEP}s as www-data (REMINDER_SWEEP_SECONDS=0 to disable)"
else
  echo "[entrypoint] reminder sweep: disabled"
fi

exec apache2-foreground
