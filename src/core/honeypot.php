<?php
// ===== ハニーポット / デコイ（偽装工作） =====
// 攻撃者が「いかにも」狙うパス（/wp-login.php, /xmlrpc.php, /wp-admin/,
// /administrator/, /phpmyadmin/, /.env, /.git/ …）に罠を仕掛け、アクセスした
// IP / UA / 送信した資格情報を data/ のログへ記録する。一定回数踏んだ IP は
// サイト全体から一時ブロックする（時限式）。
//
// 【重要】このファイルは DB にも他モジュールにも依存しない“単独動作”。
//   - 罠ページ（デコイ）は honeypot_trap() だけを呼ぶ（軽量・DB接続なし）。
//   - 本物のページは db.php 冒頭で honeypot_shield() を呼び、ブロック済みIPを弾く。
// 純 PHP + 書き込み可能な data/ だけで動くので、レンタルサーバーでもそのまま動く。
//
// 正規の利用者は罠パスを踏まないため誤爆はほぼ起きない（踏んだ時点で高確度の悪性）。

if (defined('HONEYPOT_LOADED')) {
    return;
}
define('HONEYPOT_LOADED', true);

// --- 調整パラメータ -------------------------------------------------
// 罠を HONEYPOT_TRIGGER 回踏んだら HONEYPOT_BLOCK_SECONDS 秒ブロックする。
// カウントは HONEYPOT_WINDOW 秒アクセスが無ければリセット（無害な単発を残さない）。
define('HONEYPOT_TRIGGER',       3);          // 何回で自動ブロックか
define('HONEYPOT_WINDOW',        3600);       // カウント有効期間（秒）1時間
define('HONEYPOT_BLOCK_SECONDS', 24 * 3600);  // ブロック継続時間（秒）24時間
// -------------------------------------------------------------------

function honeypot_data_dir(): string
{
    // src/core/ から見た data/（db.php と同じ基準。サーバでも docroot/data を指す）
    return __DIR__ . '/../../data';
}

// クライアントIP。プロキシ経由の可能性があるため XFF も記録するが、
// ブロック判定は詐称困難な REMOTE_ADDR を正とする。
function honeypot_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// data/ 配下の JSON を安全に読む（無ければ空配列）
function honeypot_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// 1行 JSON をログへ追記（失敗しても本処理は止めない）
function honeypot_log(string $label, array $extra = []): void
{
    $dir = honeypot_data_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return; // data/ が書けない環境では黙ってスキップ（500 を出さない）
    }
    $entry = array_merge([
        'time'   => date('c'),
        'label'  => $label,
        'ip'     => honeypot_client_ip(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'    => $_SERVER['REQUEST_URI'] ?? '',
        'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'xff'    => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
    ], $extra);
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($dir . '/honeypot.log', $line, FILE_APPEND | LOCK_EX);
}

// 罠を踏んだIPのカウントを進め、閾値を超えたら時限ブロックに昇格させる
function honeypot_escalate(string $ip): void
{
    $dir  = honeypot_data_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = $dir . '/honeypot_deny.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        $raw  = stream_get_contents($fp);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
        $now  = time();

        $rec = $data[$ip] ?? ['count' => 0, 'first' => $now, 'last' => 0, 'blocked_until' => 0];
        // 前回アクセスから WINDOW を超えていればカウントを仕切り直す
        if ($now - ($rec['last'] ?? 0) > HONEYPOT_WINDOW) {
            $rec['count'] = 0;
            $rec['first'] = $now;
        }
        $rec['count']++;
        $rec['last'] = $now;
        if ($rec['count'] >= HONEYPOT_TRIGGER) {
            $rec['blocked_until'] = $now + HONEYPOT_BLOCK_SECONDS;
        }
        $data[$ip] = $rec;

        // 期限切れレコードを掃除（ファイルの肥大化防止）
        foreach ($data as $k => $v) {
            $stale = ($now - ($v['last'] ?? 0) > HONEYPOT_WINDOW);
            $unblocked = (($v['blocked_until'] ?? 0) < $now);
            if ($stale && $unblocked) {
                unset($data[$k]);
            }
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// 指定IP（省略時は現在のクライアント）が現在ブロック中か
function honeypot_is_blocked(?string $ip = null): bool
{
    $ip   = $ip ?? honeypot_client_ip();
    $data = honeypot_read_json(honeypot_data_dir() . '/honeypot_deny.json');
    $rec  = $data[$ip] ?? null;
    return $rec && ($rec['blocked_until'] ?? 0) > time();
}

// 本物のページの入口で呼ぶ。ブロック中IPなら 403 で即終了。
function honeypot_shield(): void
{
    if (honeypot_is_blocked()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden";
        exit;
    }
}

// 送信された「資格情報っぽい値」を拾う（攻撃者のパスワードスプレーを可視化）
function honeypot_captured_creds(): array
{
    $out = [];
    foreach (['log', 'pwd', 'username', 'user', 'login', 'login_id', 'password', 'passwd', 'email'] as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') {
            $out[$k] = mb_substr((string)$_POST[$k], 0, 128); // 過大入力を切り詰め
        }
    }
    return $out;
}

// 罠の本体。デコイ用スタブから呼ぶ。記録 → 昇格 → それっぽい偽レスポンス → 終了。
function honeypot_trap(string $label): void
{
    $creds = honeypot_captured_creds();
    honeypot_log($label, $creds ? ['creds' => $creds] : []);
    honeypot_escalate(honeypot_client_ip());

    switch ($label) {
        case 'env':
        case 'git':
        case 'file':
            // 機微ファイル探索。ログインページは不自然なので、素っ気ない 404 を返す。
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "404 Not Found";
            break;

        case 'xmlrpc':
            header('Content-Type: text/xml; charset=utf-8');
            echo "<?xml version=\"1.0\"?>\n<methodResponse><params><param><value>"
               . "<string>XML-RPC server accepts POST requests only.</string>"
               . "</value></param></params></methodResponse>";
            break;

        case 'wp-login':
        case 'wp-admin':
        default:
            honeypot_render_wp_login(!empty($creds));
            break;
    }
    exit;
}

// WordPress 風の偽ログイン画面。POST 済みなら「パスワードが違う」風エラーを出し、
// 攻撃ツールに再試行させて資格情報をさらに記録する。
function honeypot_render_wp_login(bool $failed): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    $err = $failed
        ? '<div id="login_error"><strong>エラー</strong>: パスワードが正しくありません。</div>'
        : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="ja-JP">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,follow">
<title>ログイン</title>
<style>
body{background:#f0f0f1;color:#3c434a;font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:8% 0 0}
#login{width:320px;margin:0 auto;padding:8px 0 26px}
.logo{text-align:center;margin-bottom:25px;font-size:20px;font-weight:400;color:#3c434a}
form{background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:26px 24px}
label{display:block;font-size:14px;margin-bottom:16px}
input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:8px;border:1px solid #8c8f94;border-radius:3px;font-size:16px;margin-top:4px}
.button{background:#2271b1;border:1px solid #2271b1;color:#fff;padding:8px 14px;border-radius:3px;cursor:pointer;font-size:14px;float:right}
#login_error{background:#fff;border-left:4px solid #d63638;box-shadow:0 1px 1px rgba(0,0,0,.04);margin-bottom:20px;padding:12px}
.nav{font-size:13px;margin:24px 0;text-align:center}
.nav a{color:#50575e;text-decoration:none}
</style>
</head>
<body>
<div id="login">
<h1 class="logo">WordPress</h1>
$err
<form method="post" action="wp-login.php">
<label>ユーザー名またはメールアドレス
<input type="text" name="log" size="20" autocomplete="username"></label>
<label>パスワード
<input type="password" name="pwd" size="20" autocomplete="current-password"></label>
<p style="overflow:hidden"><button type="submit" class="button">ログイン</button></p>
</form>
<p class="nav"><a href="wp-login.php?action=lostpassword">パスワードをお忘れですか ?</a></p>
</div>
</body>
</html>
HTML;
}
