PHPMailer に新しいリリースが出ています。

| | バージョン |
|---|---|
| 現在（`scripts/fetch-phpmailer.sh`） | `{{CURRENT}}` |
| 最新 | `{{LATEST}}` |

リリースノート: https://github.com/PHPMailer/PHPMailer/releases/tag/{{LATEST}}

### 更新手順

PHPMailer はリポジトリに含めず、`scripts/fetch-phpmailer.sh` がビルド時に取得します
（本番は `docker/Dockerfile.oracle` の `RUN bash ./scripts/fetch-phpmailer.sh`）。
**変更するのはこのスクリプトの `VER` 一行だけ**です。

```diff
- VER="{{CURRENT}}"
+ VER="{{LATEST}}"
```

### 更新前に確認すること

- [ ] リリースノートに **BC break** が無いか。特に `PHPMailer` を継承しているコードや
      `setLanguage()` / `lang()` を呼んでいるコードへの影響
      （本プロジェクトはどちらも該当しないため、7.0.0 の static 化は無関係でした）
- [ ] `composer.json` の `require.php` が上がっていないか
      （レンタルサーバーへの移植性に関わる。7.1.1 時点では `>=5.5.0`）
- [ ] **本番と同じ PHP バージョン**で動作確認
  - 構文チェック: `php -l` を PHPMailer 3ファイル＋アプリ全体に
  - 組み立て確認: `notify.php` の `sendViaGmail()` で `preSend()` まで通し、
    生成される MIME が現行版と一致するか
  - `E_ALL` で非推奨・警告が出ないか
- [ ] **実際に Gmail SMTP で送信**して届くこと
      （ここまでやらないと接続層は検証できません）

過去の更新記録は `logs/2026-08-03_作業報告_clinic_2段階認証セットアップQRコード.md` の
「PHPMailer を 7.1.1 へ更新」に、検証手順まで含めて残してあります。

---
この Issue は `.github/workflows/check-phpmailer.yml` が週次で自動作成しています。
更新を見送る場合はこの Issue を閉じてください（同じ見出しでは再作成されません）。
