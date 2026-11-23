<?php
// ===== 外部入力の型ガード =====
// PHP のスーパーグローバルは「文字列が来る」保証がない。?date[]=x のように
// 角括弧を付けるだけで $_GET['date'] は **配列** になる。
// その配列を preg_match() / trim() / htmlspecialchars() に渡すと PHP 8 では
// TypeError（Fatal error）になり、
//   - display_errors が On ならスタックトレースと絶対パスが外部に出る
//   - 出力が始まるので後続の header() が効かず、リダイレクトで守っている経路が壊れる
// という二重の事故になる。実際 /index.php?date[]=x で再現していた。
//
// 根本的な対策は「ini でエラーを隠す」ことではなく、**入口で型を確定させる**こと。
// ini 側（docker/hardening.ini）の display_errors=Off は、ここを漏れた場合の保険。
//
// 依存なし・副作用なしの単独モジュール。レンタルサーバーでもそのまま動く。

if (defined('INPUT_GUARD_LOADED')) {
    return;
}
define('INPUT_GUARD_LOADED', true);

/**
 * スカラーなら文字列化して返し、配列・オブジェクト・null なら既定値を返す。
 * 「配列で来たら既定値扱い」＝不正入力として素直に弾ける形にするのが狙い。
 */
function str_param($value, string $default = ''): string
{
    if (is_string($value)) {
        return $value;
    }
    // int / float / bool は素直に文字列化してよい（?id=1 等）。
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string)$value;
    }
    // array / object / null / resource はここで落とす。
    return $default;
}

/** $_GET から文字列として取り出す */
function get_str(string $key, string $default = ''): string
{
    return str_param($_GET[$key] ?? null, $default);
}

/** $_POST から文字列として取り出す */
function post_str(string $key, string $default = ''): string
{
    return str_param($_POST[$key] ?? null, $default);
}

/** $_REQUEST（GET/POST 兼用の画面）から文字列として取り出す */
function req_str(string $key, string $default = ''): string
{
    return str_param($_REQUEST[$key] ?? null, $default);
}

/**
 * 'YYYY-MM-DD' 形式で、かつ暦の上に実在する日付なら true。
 * 文字列全体が厳密にこの形式のときだけ通す（終端は \z で固定）。
 */
function valid_ymd(string $s): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $s, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * 'YYYY-MM' 形式で、月が 01〜12 なら true。
 * 文字列全体が厳密にこの形式のときだけ通す（終端は \z で固定）。
 */
function valid_ym(string $s): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})\z/', $s, $m)) {
        return false;
    }
    return checkdate((int)$m[2], 1, (int)$m[1]);
}

/**
 * UTF-8 の文字数が $max 以下なら true。UTF-8 として正しくない文字列は false。
 */
function str_within(string $s, int $max): bool
{
    return preg_match('/\A.{0,' . $max . '}\z/su', $s) === 1;
}

/**
 * POST の送信元が、このサイト自身のページかを確かめる。
 * Origin を見て、無ければ Referer を見る。どちらも無ければ true。
 * 比べるのはホスト名とポートで、Host ヘッダーと一致すれば true。
 * 既定のポート（80・443）は両方から除いて比べる。
 */
function post_from_same_site(array $server): bool
{
    $src = (string)($server['HTTP_ORIGIN'] ?? '');
    if ($src === '') {
        $src = (string)($server['HTTP_REFERER'] ?? '');
    }
    if ($src === '') {
        return true;
    }
    $p = parse_url($src);
    if (!is_array($p) || !isset($p['host']) || $p['host'] === '') {
        return false;
    }
    $port = (isset($p['port']) && !in_array($p['port'], [80, 443], true)) ? ':' . $p['port'] : '';
    $from = strtolower($p['host']) . $port;
    $host = (string)preg_replace('/:(80|443)\z/', '', strtolower((string)($server['HTTP_HOST'] ?? '')));
    return $host !== '' && $from === $host;
}
