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
