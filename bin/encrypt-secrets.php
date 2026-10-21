<?php
// 既存の平文の秘密項目（SMTPパスワード等）を暗号化し直す一度きりの移行スクリプト。
//
//   docker exec -u www-data <container> php /var/www/html/clinic-reservation/bin/encrypt-secrets.php
//
// ⚠ Web と同じユーザーで実行すること（root で回すと data/ の所有者が壊れ、
//    以後 Web 側の書き込みが無言で失敗する）。
// ⚠ APP_SECRET_KEY が未設定なら何もしない（平文のまま動く従来仕様）。
//
// 何度実行しても安全（暗号化済みの値は二重に暗号化しない）。

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/core/db.php';

$pdo = db();
$keys = secretSettingKeys();

if (secretKey() === null) {
    fwrite(STDERR, "APP_SECRET_KEY が未設定です。暗号化せずに終了します。\n");
    fwrite(STDERR, "鍵の作り方: php -r 'echo base64_encode(random_bytes(32)), \"\n\";'\n");
    exit(1);
}

$changed = 0;
$already = 0;
$empty   = 0;
foreach ($keys as $k) {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$k]);
    $cur = $stmt->fetchColumn();
    if ($cur === false || $cur === '') { $empty++; continue; }
    if (isEncryptedSecret((string)$cur)) { $already++; continue; }

    $enc = encryptSecret((string)$cur);
    $up = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
    $up->execute([$enc, $k]);

    // 書いたものが元に戻せることを確かめてから「済」とする。
    $chk = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $chk->execute([$k]);
    if (decryptSecret((string)$chk->fetchColumn()) !== (string)$cur) {
        fwrite(STDERR, "[$k] 暗号化後に復号できませんでした。中断します。\n");
        exit(1);
    }
    echo "[$k] 暗号化しました\n";
    $changed++;
}
echo "完了: 暗号化 {$changed} 件 / すでに暗号化済み {$already} 件 / 空 {$empty} 件\n";
