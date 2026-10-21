<?php
// ===== 追記ログの共通書き出し（サイズ上限つき） =====
// data/ 配下のテキストログ（honeypot.log / mail.log）はこれを通して書く。
//
// 【なぜ必要か】
// honeypot.log は「攻撃者が好きなだけ太らせられるログ」だった。
//   - デコイ（/wp-login.php 等）は1リクエスト＝1行を無条件に追記する
//   - 1行に UA と URI を**無加工・無制限**で載せていたので、Apache のヘッダ上限
//     （LimitRequestFieldSize 既定 8190 バイト）まで膨らませられる。
//     UA と URI で 1 リクエストあたり約 16KB。
//   - ローテーションも上限も無い
// つまり外部から任意に書き込める無限ログで、放っておけばディスクが埋まる。
// ディスクが埋まると SQLite が書けなくなって予約システムが止まるだけでなく、
// 同じ VM に同居している他サービス（db / ec-api / backup）まで道連れになる。
// 「攻撃を記録する仕組み」が「攻撃手段」になっていた。
//
// 【方針】
//   1. 1行あたりの長さを上限で切る（呼び出し側で ua / uri を切り詰める）
//   2. ファイルが上限サイズを超えたら .1 へ退避する（世代は1つだけ保持）
//      → ディスク使用量は最大でも (上限 × 2) に収まる
//   3. 書けない・失敗しても本処理は絶対に止めない（@ 抑制 + 例外を出さない）
//
// 外部依存なし・副作用なしの単独モジュール。honeypot.php が DB 非依存で
// 動く前提を壊さないよう、ここも純 PHP だけで書く。

if (defined('LOGFILE_LOADED')) {
    return;
}
define('LOGFILE_LOADED', true);

// 1ファイルあたりの上限（バイト）。超えたら .1 へ退避して新しく書き始める。
// 2MB は「直近の攻撃を追うには十分、ディスクを脅かすには小さすぎる」目安。
// 退避世代は1つだけなので、1ログあたり最大 4MB。
if (!defined('LOGFILE_MAX_BYTES')) {
    define('LOGFILE_MAX_BYTES', 2 * 1024 * 1024);
}

// 1回の追記で書ける最大バイト数。呼び出し側の切り詰め漏れに対する最後の砦。
if (!defined('LOGFILE_MAX_ENTRY_BYTES')) {
    define('LOGFILE_MAX_ENTRY_BYTES', 8 * 1024);
}

/**
 * 外から与えられた文字列をログへ載せられる長さに切り詰める。
 * - 制御文字を除去（ログに改行を注入して「偽の行」を作られるのを防ぐ）
 * - $max バイトで打ち切り、切った印を残す
 *
 * UA / URI / 資格情報のように「攻撃者が長さを決められる値」は必ずこれを通すこと。
 */
function log_clip(string $value, int $max = 256): string
{
    // 改行・タブを含む制御文字は潰す。json_encode でも改行はエスケープされるが、
    // JSON 以外の形式（mail.log）でも使うのでここで共通に落とす。
    // ⚠ /u 修飾子は付けない。UA は不正な UTF-8 で送られてくることがあり、
    //    /u だと preg_replace が null を返して値ごと消えてしまう。ここはバイト処理でよい。
    $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    if ($value === null) {
        return '(unprintable)';
    }
    if (strlen($value) <= $max) {
        return $value;
    }
    // バイト数で切る。途中で切れて不正な UTF-8 になりうるが、JSON 化側で
    // JSON_INVALID_UTF8_SUBSTITUTE を指定しているので壊れた行にはならない。
    return substr($value, 0, $max) . '…(切詰)';
}

/**
 * ログへ1件追記する。上限を超えていたら先に1世代だけ退避する。
 * 失敗しても例外は投げない（ログのために本処理を止めない）。
 */
function log_append(string $path, string $text, int $maxBytes = LOGFILE_MAX_BYTES): void
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return; // data/ が書けない環境では黙ってスキップ（500 を出さない）
    }

    if (strlen($text) > LOGFILE_MAX_ENTRY_BYTES) {
        $text = substr($text, 0, LOGFILE_MAX_ENTRY_BYTES) . "…(切詰)\n";
    }

    // ローテーション: 上限を超えていたら .1 へ退避（既存の .1 は捨てる）。
    // clearstatcache を挟まないと、同一リクエスト内の連続書き込みで古いサイズを見る。
    clearstatcache(true, $path);
    if (is_file($path) && @filesize($path) >= $maxBytes) {
        @rename($path, $path . '.1');   // rename は原子的。失敗してもそのまま追記を続ける
    }

    @file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
}
