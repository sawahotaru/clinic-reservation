<?php
// ===== TOTP（RFC 6238）2段階認証 — 純PHP・外部依存なし =====
// Google Authenticator / Authy / Microsoft Authenticator 等と互換。
// 秘密鍵は base32、検証は標準の hash_hmac('sha1')。Composer も拡張も不要
// （どのレンタルサーバーでも動く）。設定値は settings テーブルに保存：
//   twofa_enabled  '0'|'1'
//   twofa_secret   base32 の秘密鍵
//   twofa_recovery リカバリコード(sha256)の JSON 配列（単回使用で消費）

/** base32 デコード（RFC4648・パディング/記号は無視） */
function base32_decode(string $b32): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $buffer = 0; $bits = 0; $out = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $buffer = ($buffer << 5) | strpos($map, $b32[$i]);
        $bits += 5;
        if ($bits >= 8) { $bits -= 8; $out .= chr(($buffer >> $bits) & 0xFF); }
    }
    return $out;
}

/** ランダムな base32 秘密鍵（既定20バイト＝32文字相当） */
function totp_generate_secret(int $bytes = 20): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $raw = random_bytes($bytes);
    $buffer = 0; $bits = 0; $out = '';
    for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
        $buffer = ($buffer << 8) | ord($raw[$i]);
        $bits += 8;
        while ($bits >= 5) { $bits -= 5; $out .= $map[($buffer >> $bits) & 31]; }
    }
    if ($bits > 0) { $out .= $map[($buffer << (5 - $bits)) & 31]; }
    return $out;
}

/** 指定タイムスライスの6桁コードを生成 */
function totp_code(string $secret, int $slice): string
{
    $key = base32_decode($secret);
    $bin = "\0\0\0\0" . pack('N', $slice); // 8バイトのビッグエンディアン・カウンタ
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
         (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad((string)$part, 6, '0', STR_PAD_LEFT);
}

/**
 * 全角の英数字・ハイフンを半角へ寄せる（mbstring 非依存）。
 * PCでは日本語IMEが有効なまま6桁を打ってしまうことが多く、全角のまま送られると
 * preg_replace('/\D/') が全部消してしまい「コードが違います」になる。入口で救う。
 */
function totp_normalize_input(string $s): string
{
    static $zen = ['０','１','２','３','４','５','６','７','８','９',
                   'ａ','ｂ','ｃ','ｄ','ｅ','ｆ','Ａ','Ｂ','Ｃ','Ｄ','Ｅ','Ｆ',
                   '－','ー','−','―','‐','　'];
    static $han = ['0','1','2','3','4','5','6','7','8','9',
                   'a','b','c','d','e','f','A','B','C','D','E','F',
                   '-','-','-','-','-',''];
    return str_replace($zen, $han, trim($s));
}

/** 現在時刻の ±$window スライスでコードを検証（端末との時計ズレ許容） */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', totp_normalize_input($code));
    if (strlen($code) !== 6 || $secret === '') return false;
    $now = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + $i), $code)) return true;
    }
    return false;
}

/** authenticator 登録用の otpauth:// URI */
function totp_uri(string $secret, string $label, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// ---- リカバリコード（authenticator を失った時の予備） ----

/** 平文のリカバリコードを生成（保存はしない。呼び出し側で1度だけ表示） */
function twofa_generate_recovery(int $n = 8): array
{
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        // 見やすい 4-4 桁の英数字
        $codes[] = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
    }
    return $codes;
}

/** リカバリコード群を sha256 で保存用ハッシュ配列にする */
function twofa_hash_recovery(array $codes): array
{
    return array_map(fn($c) => hash('sha256', $c), $codes);
}

/** 入力コードが未使用のリカバリコードなら消費して true。settings を更新する。 */
function twofa_consume_recovery(PDO $pdo, string $code): bool
{
    $code = strtolower(totp_normalize_input($code));   // リカバリコードも全角で入りうる
    $stored = json_decode(authGet($pdo, 'twofa_recovery', '[]'), true);
    if (!is_array($stored) || !$stored) return false;
    $h = hash('sha256', $code);
    $idx = array_search($h, $stored, true);
    if ($idx === false) return false;
    unset($stored[$idx]);
    authSet($pdo, 'twofa_recovery', json_encode(array_values($stored)));
    return true;
}

/** 2FA が有効か */
function twofaEnabled(PDO $pdo): bool
{
    return authGet($pdo, 'twofa_enabled', '0') === '1' && authGet($pdo, 'twofa_secret', '') !== '';
}
