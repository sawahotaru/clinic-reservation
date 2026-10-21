<?php
// ===== 設定値の暗号化保存 =====
//
// settings テーブルには SMTP パスワード（Gmail アプリパスワード）が入る。
// DB ファイルは data/ にあり、万一そこが読まれたときに平文のまま渡さないための層。
//
// ⚠ これは「DBファイルが漏れても鍵が無ければ読めない」ための措置であって、
//    data/ を公開しないこと自体の代わりにはならない（2026-10-22 の露出事故を参照）。
//    鍵は data/ の外（環境変数）に置く。DB に鍵を入れたら意味が無い。
//
// 鍵: 環境変数 APP_SECRET_KEY（base64 の 32 バイト）。
//     生成: php -r 'echo base64_encode(random_bytes(32)), "\n";'
//
// 鍵が未設定なら**平文のまま保存する**（従来どおり動く）。
// これは配布物として、鍵を用意しなくても clone してすぐ動かせるようにするため。
// その場合は管理画面に注意書きを出す（admin/mail.php）。

/** 暗号化済みの値に付く目印。これで平文と区別する。 */
const SECRET_PREFIX = 'enc:v1:';

/** 暗号化に使う設定キー（ここに足せば自動で対象になる） */
function secretSettingKeys(): array
{
    return ['smtp_pass'];
}

/** 鍵を取り出す。未設定・不正なら null（＝平文運用にフォールバック）。 */
function secretKey(): ?string
{
    $raw = getenv('APP_SECRET_KEY');
    if ($raw === false || $raw === '') {
        return null;
    }
    $key = base64_decode(trim($raw), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        // 設定ミスで黙って平文に落ちると気づけないので、記録だけは残す。
        error_log('[secrets] APP_SECRET_KEY が不正です（base64 の32バイトが必要）。平文で保存します。');
        return null;
    }
    return $key;
}

/** 暗号化されているか */
function isEncryptedSecret(string $value): bool
{
    return str_starts_with($value, SECRET_PREFIX);
}

/**
 * 暗号化する。鍵が無ければ平文のまま返す（＝従来動作）。
 * 既に暗号化済みの値は二重に暗号化しない。
 */
function encryptSecret(string $plain): string
{
    if ($plain === '' || isEncryptedSecret($plain)) {
        return $plain;
    }
    $key = secretKey();
    if ($key === null) {
        return $plain;
    }
    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return SECRET_PREFIX . base64_encode($nonce . $cipher);
}

/**
 * 復号する。平文（旧データ）はそのまま返すので、移行中でも動く。
 * 鍵が無い／合わない場合は空文字を返す。
 * ⚠ ここで元の暗号文を返してしまうと、それが SMTP パスワードとして
 *    送信処理に渡り、認証失敗の原因が分からなくなる。
 */
function decryptSecret(string $stored): string
{
    if ($stored === '' || !isEncryptedSecret($stored)) {
        return $stored; // 旧データ（平文）。移行前でも動く。
    }
    $key = secretKey();
    if ($key === null) {
        error_log('[secrets] 暗号化された値があるのに APP_SECRET_KEY がありません。');
        return '';
    }
    $bin = base64_decode(substr($stored, strlen(SECRET_PREFIX)), true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        error_log('[secrets] 暗号文が壊れています。');
        return '';
    }
    $nonce  = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    if ($plain === false) {
        error_log('[secrets] 復号に失敗しました（鍵が違う可能性）。');
        return '';
    }
    return $plain;
}
