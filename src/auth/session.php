<?php
// ===== セッションと CSRF トークン =====
// 管理画面のセッションは、ここを通してしか開始しない。
//
// 【この層でしていること】
//  1. Cookie 属性を明示する（HttpOnly・SameSite=Lax・HTTPS のときだけ Secure）。
//     SameSite の既定の扱いはブラウザによって違うので、既定に頼らない。
//  2. 管理画面の状態を変える POST には CSRF トークンを要求する
//     （ID/パスワード変更・2段階認証の無効化を含む）。
//     Basic 認証の資格情報はブラウザが自動で付けるので、CSRF の防壁にはならない。
//  3. 権限が上がるとき（パスワード認証の通過・2段階認証の通過）にセッションIDを
//     打ち直す（session fixation 対策）。
//     ※ session.use_strict_mode=1（docker/hardening.ini）とは別の対策で、両方必要。
//  4. ログアウトでは $_SESSION のクリア・Cookie の削除・session_destroy() を行う。
//
// 外部依存なし。レンタルサーバーへ持って行ってもそのまま動く。

if (defined('SESSION_GUARD_LOADED')) {
    return;
}
define('SESSION_GUARD_LOADED', true);

/**
 * この接続が（利用者から見て）HTTPS か。
 *
 * 前段のリバースプロキシで TLS を終端する構成では、Apache 自身は平文の HTTP で
 * 受けるため $_SERVER['HTTPS'] は立たない。前段が付ける X-Forwarded-Proto を見る。
 *
 * ⚠ 「XFP を信じて大丈夫か」: 前段は自分が終端したスキームで
 *    このヘッダを上書きするので、クライアントが偽の値を混ぜても届かない。
 *    仮に直結で https と詐称されても、起きるのは「自分の Cookie に Secure が
 *    付いて自分に届かなくなる」だけで、こちらが損をする方向には倒れない。
 */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($proto) && $proto !== '') {
        // "https, http" のように連なる場合は左端（利用者に一番近い側）を見る
        $first = strtolower(trim(explode(',', $proto)[0]));
        return $first === 'https';
    }
    return false;
}

/**
 * セッションを安全な Cookie 属性で開始する。すでに開始済みなら何もしない。
 *
 * secure は HTTPS のときだけ立てる。ローカルは HTTP(8501) で動かすので、
 * 常時 true にすると Cookie がそもそも保存されず「ログインできない」になる。
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Cookie のスコープはこのディレクトリ（管理画面）配下に限定する。
    // '/' にすると、同じホストの別のパスにも管理セッションの Cookie が送られる。
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $path = rtrim($dir, '/') . '/';

    session_set_cookie_params([
        'path'     => $path,
        'httponly' => true,              // JS から読めなくする（XSS 時の被害縮小）
        'secure'   => request_is_https(),// HTTPS のときだけ。HTTP ローカルでは落とす
        'samesite' => 'Lax',             // 他サイトからの POST には Cookie を付けない
    ]);
    session_start();
}

/**
 * 権限の境界でセッションIDを打ち直す（session fixation 対策）。
 * パスワード認証を通った時と、2段階認証を通った時の**両方**で呼ぶこと。
 * true を渡しているので古いセッションファイルも消える。
 */
function session_rotate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        // 権限が変わったらトークンも作り直す（昇格前に配ったトークンを失効させる）。
        unset($_SESSION['csrf_token']);
    }
}

/** ログアウト。中身・Cookie・サーバ側の実体の3つを消す。 */
function session_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];                       // ① 中身（admin フラグ・2FA 途中状態）を消す
    if (ini_get('session.use_cookies')) { // ② ブラウザ側の Cookie を失効させる
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();                    // ③ サーバ側の実体を消す
}

// ---- CSRF トークン ----

/** このセッションの CSRF トークン（無ければ作る） */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** フォームに埋める hidden input を出力する */
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="'
       . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * POST に正しいトークンが付いているか。
 * 比較は hash_equals（タイミング差で当てられないように）。
 */
function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '') {
        return false;
    }
    $have = $_SESSION['csrf_token'] ?? '';
    return is_string($have) && $have !== '' && hash_equals($have, $sent);
}

/**
 * 状態を変える POST の入口で呼ぶ。トークンが無い/違えば 400 で即終了。
 *
 * ⚠ 400 を返すのは、403（Basic 認証や honeypot の拒否）と区別して
 *    ログから原因を切り分けられるようにするため。
 *    非標準コード（419 等）は Apache が握り潰して 500 にしてしまうので使わない
 *    （実測: http_response_code(419) → クライアントには 500 が届いた）。
 * ⚠ 「画面にエラーを出して続行」はしない。CSRF は利用者の操作ではないので、
 *    続行する意味が無く、書き込み処理に一歩でも進ませない方が安全。
 */
function csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (csrf_valid()) {
        return;
    }
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ja"><meta charset="UTF-8">'
       . '<title>セッションの有効期限</title>'
       . '<p>送信を受け付けられませんでした（セッションの有効期限切れ、'
       . 'または不正な送信元からの操作）。</p>'
       . '<p>お手数ですが、前の画面を<strong>再読み込み</strong>してから'
       . 'もう一度操作してください。</p>';
    exit;
}
