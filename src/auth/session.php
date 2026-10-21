<?php
// ===== セッションと CSRF トークン =====
// 管理画面のセッションは、ここを通してしか開始しない。
//
// 【なぜ必要だったか】
//  1. Cookie 属性が PHP の既定のまま（HttpOnly=0 / Secure=0 / SameSite 未指定）だった。
//     - HttpOnly が無い      … XSS が1つ出た瞬間にセッションIDを JS で抜ける
//     - Secure が無い        … http に降格させられた経路で平文のまま飛ぶ
//     - SameSite 未指定      … Chrome は Lax 相当に倒すが、**Firefox は倒さない**。
//                              「最近のブラウザが守ってくれる」は環境依存の思い込みで、
//                              Firefox の管理者に対しては CSRF がそのまま成立する。
//  2. CSRF トークンがどこにも無かった（grep -i csrf が0件）。管理画面の
//     予約キャンセル・休業登録/解除・営業設定・メール設定保存/テスト送信、
//     そして **ID/パスワード変更・2段階認証の無効化** まで素の POST だった。
//     前段の Basic 認証は「ブラウザが自動で付ける」ので CSRF の防壁にならない。
//     管理者がログイン中に罠ページを踏むだけで、パスワードを攻撃者の値に
//     書き換えられる（＝アカウント乗っ取り）状態だった。
//  3. session_regenerate_id() が一度も呼ばれていなかった。
//     「未ログイン → パスワード認証通過 → 2FA 通過 → 管理者」と権限が上がるのに
//     セッションIDが最初から最後まで同じ。事前に ID を固定できれば、
//     攻撃者は管理者になった後のセッションをそのまま使える（session fixation）。
//     ※ php.ini 側の session.use_strict_mode=1（docker/hardening.ini）は
//       「サーバが知らない ID を受け入れない」対策で、権限昇格時の
//       ID 付け替えとは別物。両方必要。
//  4. ログアウトが session_destroy() だけで、$_SESSION のクリアも
//     Cookie の削除もしていなかった。
//
// 外部依存なし。レンタルサーバーへ持って行ってもそのまま動く。

if (defined('SESSION_GUARD_LOADED')) {
    return;
}
define('SESSION_GUARD_LOADED', true);

/**
 * この接続が（利用者から見て）HTTPS か。
 *
 * 本番は Cloudflare → Caddy → Apache の順で、Apache 自身は平文の HTTP で
 * 受けるため $_SERVER['HTTPS'] は立たない。前段が付ける X-Forwarded-Proto を見る。
 *
 * ⚠ 「XFP を信じて大丈夫か」: Caddy / Cloudflare は自分が終端したスキームで
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
    // lab.4510.be は /ec/ 等と同じホストを共有しているので、'/' にすると
    // 無関係なアプリにも管理セッションの Cookie が送られてしまう。
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
