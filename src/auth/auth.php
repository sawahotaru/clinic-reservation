<?php
require_once __DIR__ . '/../core/settings.php';
require_once __DIR__ . '/../core/config.php';

// ===== 管理アカウントと認証・リセット =====
// 管理アカウント情報は settings テーブルに保存（admin_id / admin_pass_hash / admin_recovery_email）。
// パスワードは password_hash でハッシュ化。リセットはトークン（auth_tokens）＋メールリンク。

/** テーブル準備と初期アカウントの登録（初回のみ） */
function ensureAdmin(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS auth_tokens (
        token_hash TEXT PRIMARY KEY,
        purpose    TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        used       INTEGER NOT NULL DEFAULT 0
    )');

    // 既定は「ファイル主導」: ログインID/パスワード/メールは config.php を直接参照する。
    // 一度も管理画面でアカウントを変更していない状態。
    if (authGet($pdo, 'admin_pass_custom', '') === '') {
        authSet($pdo, 'admin_pass_custom', '0');
    }

    // 緊急復旧: config.php で FORCE_INITIAL_ADMIN を有効にすると、ファイル主導へ戻す
    if (defined('FORCE_INITIAL_ADMIN') && FORCE_INITIAL_ADMIN) {
        resetLoginToInitial($pdo);
    }
}

/** 管理画面で独自に設定済みか（true=UI主導 / false=ファイル主導） */
function isCustomAdmin(PDO $pdo): bool
{
    return authGet($pdo, 'admin_pass_custom', '0') === '1';
}

/** settings から1件取得 */
function authGet(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

/** settings へ1件保存（upsert） */
function authSet(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

// ファイル主導なら config.php の値、UI主導なら settings の値
function adminId(PDO $pdo): string
{
    return isCustomAdmin($pdo) ? authGet($pdo, 'admin_id', INITIAL_ADMIN_ID) : INITIAL_ADMIN_ID;
}
function recoveryEmail(PDO $pdo): string
{
    return isCustomAdmin($pdo) ? authGet($pdo, 'admin_recovery_email', INITIAL_ADMIN_EMAIL) : INITIAL_ADMIN_EMAIL;
}

/** ログイン認証（ID＝メールは大小無視）。ファイル主導は平文照合、UI主導はハッシュ照合。 */
function verifyLogin(PDO $pdo, string $id, string $password): bool
{
    if (strcasecmp(trim($id), adminId($pdo)) !== 0) {
        return false;
    }
    if (isCustomAdmin($pdo)) {
        $hash = authGet($pdo, 'admin_pass_hash', '');
        return $hash !== '' && password_verify($password, $hash);
    }
    // ファイル主導: config.php の平文と timing-safe 比較
    return hash_equals(INITIAL_ADMIN_PASSWORD, $password);
}

/** 初めてUI主導へ切り替える時、現在の実効値（＝ファイル値）をDBへ退避してから切替 */
function startCustomAdmin(PDO $pdo): void
{
    if (isCustomAdmin($pdo)) return;
    authSet($pdo, 'admin_id', INITIAL_ADMIN_ID);
    authSet($pdo, 'admin_recovery_email', INITIAL_ADMIN_EMAIL);
    authSet($pdo, 'admin_pass_hash', password_hash(INITIAL_ADMIN_PASSWORD, PASSWORD_DEFAULT));
    authSet($pdo, 'admin_pass_custom', '1');
}

// 管理画面/再設定でアカウントを変更すると「UI主導」に切り替わり、以降 config.php では上書きしない。
function setPassword(PDO $pdo, string $newPassword): void
{
    startCustomAdmin($pdo);
    authSet($pdo, 'admin_pass_hash', password_hash($newPassword, PASSWORD_DEFAULT));
}
function setAdminId(PDO $pdo, string $id): void
{
    startCustomAdmin($pdo);
    authSet($pdo, 'admin_id', trim($id));
}
function setRecoveryEmail(PDO $pdo, string $email): void
{
    startCustomAdmin($pdo);
    authSet($pdo, 'admin_recovery_email', trim($email));
}

/** ログイン情報を初期値（config.php）に戻す＝ファイル主導へ戻す */
function resetLoginToInitial(PDO $pdo): void
{
    authSet($pdo, 'admin_pass_custom', '0');
}

// ---- リセットトークン ----

/** トークン発行（平文を返す。DBにはハッシュと有効期限を保存） */
function issueToken(PDO $pdo, string $purpose): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_tokens(token_hash, purpose, expires_at, used) VALUES(?, ?, ?, 0)')
        ->execute([hash('sha256', $token), $purpose, time() + 3600]); // 1時間有効
    return $token;
}

/** トークンが有効か（消費しない。フォーム表示用） */
function checkToken(PDO $pdo, string $token, string $purpose): bool
{
    if ($token === '') return false;
    $st = $pdo->prepare('SELECT 1 FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used = 0 AND expires_at > ?');
    $st->execute([hash('sha256', $token), $purpose, time()]);
    return (bool)$st->fetchColumn();
}

/** トークンを使用（有効なら消費して true。実行時用） */
function useToken(PDO $pdo, string $token, string $purpose): bool
{
    if (!checkToken($pdo, $token, $purpose)) return false;
    $pdo->prepare('UPDATE auth_tokens SET used = 1 WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
    return true;
}

/** リクエスト元から基準URLを組み立てる（リセットリンク用） */
function baseUrl(): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir    = rtrim($dir, '/');
    return "{$scheme}://{$host}{$dir}";
}

/** リセットリンクを初期メール宛に送る。[成功?, エラー文] */
function sendResetLink(PDO $pdo, string $purpose, string $url): array
{
    require_once __DIR__ . '/../mail/notify.php';
    $s  = getSettings($pdo);
    $to = recoveryEmail($pdo);

    if ($purpose === 'login') {
        $subject = '【予約システム】ログイン情報のリセット';
        $body = "ログイン情報を初期設定に戻すためのリンクです（1時間有効）。\n\n{$url}\n\n"
              . "このリンクを開いて実行すると、ログインIDとパスワードがサーバー上の初期値に戻ります。\n"
              . "お心当たりがなければ、このメールは破棄してください。\n";
    } else {
        $subject = '【予約システム】パスワード再設定';
        $body = "パスワードを再設定するためのリンクです（1時間有効）。\n\n{$url}\n\n"
              . "リンクを開いて新しいパスワードを設定してください。\n"
              . "お心当たりがなければ、このメールは破棄してください。\n";
    }
    return sendMail($s, $to, $subject, $body, null, 'reset_' . $purpose);
}
