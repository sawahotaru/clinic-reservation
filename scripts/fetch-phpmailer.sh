#!/usr/bin/env bash
# PHPMailer（Gmail SMTP送信用）を src/mail/lib/PHPMailer/ に取得する。
# リポジトリには含めず、デプロイ時およびローカル初回セットアップ時に実行する。
set -euo pipefail

VER="v6.9.1"
DEST="$(cd "$(dirname "$0")/.." && pwd)/src/mail/lib/PHPMailer"
BASE="https://raw.githubusercontent.com/PHPMailer/PHPMailer/${VER}/src"

mkdir -p "$DEST"
for f in Exception.php PHPMailer.php SMTP.php; do
  curl -fsSL "$BASE/$f" -o "$DEST/$f"
done
echo "PHPMailer ${VER} を $DEST に取得しました。"
