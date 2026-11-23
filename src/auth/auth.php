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

    // ログイン試行回数の制限（ブルートフォース対策）用
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_throttle (
        k            TEXT PRIMARY KEY,
        fails        INTEGER NOT NULL DEFAULT 0,
        first_at     INTEGER NOT NULL DEFAULT 0,
        last_at      INTEGER NOT NULL DEFAULT 0,
        locked_until INTEGER NOT NULL DEFAULT 0
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

/** 初めてUI主導へ切り替える。ID・メールはファイルの値を引き継ぎ、パスワードは新しい値で保存する。 */
function startCustomAdmin(PDO $pdo, string $newPassword): void
{
    if (isCustomAdmin($pdo)) return;
    authSet($pdo, 'admin_id', INITIAL_ADMIN_ID);
    authSet($pdo, 'admin_recovery_email', INITIAL_ADMIN_EMAIL);
    authSet($pdo, 'admin_pass_hash', password_hash($newPassword, PASSWORD_DEFAULT));
    authSet($pdo, 'admin_pass_custom', '1');
}

// 新しいパスワードを設定すると「UI主導」に切り替わり、以降 config.php では上書きしない。
// ログインID・再設定先メールの変更は、UI主導になってから受け付ける。
function setPassword(PDO $pdo, string $newPassword): void
{
    if (hash_equals(INITIAL_ADMIN_PASSWORD, $newPassword)) {
        throw new InvalidArgumentException('新しいパスワードは初期パスワードと別にしてください。');
    }
    startCustomAdmin($pdo, $newPassword);
    authSet($pdo, 'admin_pass_hash', password_hash($newPassword, PASSWORD_DEFAULT));
}
/** ログインIDを変更。UI主導のときだけ保存し、保存したら true。 */
function setAdminId(PDO $pdo, string $id): bool
{
    if (!isCustomAdmin($pdo)) return false;
    authSet($pdo, 'admin_id', trim($id));
    return true;
}
/** 再設定先メールを変更。UI主導のときだけ保存し、保存したら true。 */
function setRecoveryEmail(PDO $pdo, string $email): bool
{
    if (!isCustomAdmin($pdo)) return false;
    authSet($pdo, 'admin_recovery_email', trim($email));
    return true;
}

/** 新しいパスワードの検査。問題が無ければ空文字、あれば利用者向けの文言を返す。 */
function newPasswordError(string $p1, string $p2): string
{
    if (strlen($p1) < 8) return 'パスワードは8文字以上にしてください。';
    if ($p1 !== $p2) return '確認用パスワードが一致しません。';
    if (hash_equals(INITIAL_ADMIN_PASSWORD, $p1)) return '初期パスワードとは別のものを設定してください。';
    return '';
}

/** 管理セッションの世代。これが変わると、それ以前のログイン状態は使えなくなる。 */
function adminSessionEpoch(PDO $pdo): string
{
    return authGet($pdo, 'admin_session_epoch', '');
}

/** 既存の管理セッションと、未使用のリンク（トークン）をすべて無効にする。 */
function revokeAdminAccess(PDO $pdo): void
{
    authSet($pdo, 'admin_session_epoch', bin2hex(random_bytes(16)));
    $pdo->exec('UPDATE auth_tokens SET used = 1 WHERE used = 0');
}

/** ログイン情報を初期値（config.php）に戻す＝ファイル主導へ戻す。2FAも解除する（FORCE_INITIAL_ADMIN 用）。
 *  状態が変わったときだけ、既存の管理セッションと未使用のリンクを無効にする。 */
function resetLoginToInitial(PDO $pdo): void
{
    $changed = isCustomAdmin($pdo) || authGet($pdo, 'twofa_enabled', '0') === '1';
    authSet($pdo, 'admin_pass_custom', '0');
    // 端末/認証アプリを失っても FORCE_INITIAL_ADMIN で必ず入れるよう、2段階認証も無効化する
    authSet($pdo, 'twofa_enabled', '0');
    if ($changed) {
        revokeAdminAccess($pdo);
    }
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
        $body = "ログイン情報をリセットするためのリンクです（1時間有効）。\n\n{$url}\n\n"
              . "リンクを開くと、ログインIDの確認と新しいパスワードの設定ができます。\n"
              . "お心当たりがなければ、このメールは破棄してください。\n";
    } else {
        $subject = '【予約システム】パスワード再設定';
        $body = "パスワードを再設定するためのリンクです（1時間有効）。\n\n{$url}\n\n"
              . "リンクを開いて新しいパスワードを設定してください。\n"
              . "お心当たりがなければ、このメールは破棄してください。\n";
    }
    return sendMail($s, $to, $subject, $body, null, 'reset_' . $purpose);
}
