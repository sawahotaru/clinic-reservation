<?php
/**
 * 来院前リマインダーの送信（コマンドライン用）。
 *
 *   php bin/send-reminders.php          送信する
 *   php bin/send-reminders.php --dry    送信せず、対象だけ一覧する
 *   php bin/send-reminders.php --quiet  送るものが無ければ何も出力しない（定期実行用）
 *
 * cron の例（毎時0分に実行。時刻の判定はアプリ側の設定で行うので毎時で構わない）:
 *   0 * * * * cd /path/to/clinic-reservation && php bin/send-reminders.php >> data/cron.log 2>&1
 *
 * Docker なら（⚠ Webと同じユーザーで実行する。理由は末尾の alignDataOwnership 参照）:
 *   docker compose exec -T -u www-data web php /var/www/html/bin/send-reminders.php
 *
 * ※ cron を用意できない環境でも、公開ページへのアクセス契機で送られる（remindersTick）。
 *   その場合このスクリプトは不要だが、アクセスが無い時間帯は送信も止まる点に注意。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);   // Web から叩けてしまう場所に置かれても実行させない
    exit;
}

require __DIR__ . '/../src/core/db.php';
require __DIR__ . '/../src/mail/reminders.php';

$dry = in_array('--dry', $argv, true);
// 定期実行から使う。10分おきに「対象0件」と言われ続けると、ログが読めなくなって
// 本当に見たい行（失敗）が埋もれる。手で叩いたときは今までどおり必ず何か返す。
$quiet = in_array('--quiet', $argv, true);
$s   = getSettings($pdo);
$cfg = reminderConfig($s);

$stamp = date('Y-m-d H:i:s');

if (!$cfg['enabled'] && !$dry) {
    if (!$quiet) {
        echo "[{$stamp}] リマインダーは無効です（管理画面の「メール設定」で有効にしてください）\n";
    }
    exit(0);
}

$due = dueReminders($pdo, $s);

if ($dry) {
    echo "[{$stamp}] 設定: " . ($cfg['enabled'] ? '有効' : '無効')
       . " / {$cfg['days']}日前 / {$cfg['hour']}時以降 — 対象 " . count($due) . " 件\n";
    foreach ($due as $r) {
        echo "  #{$r['id']}  {$r['date']} {$r['time']}  {$r['name']} 様  <{$r['email']}>\n";
    }
    exit(0);
}

$res = sendDueReminders($pdo);
alignDataOwnership();
// --quiet でも「送った」「失敗した」は必ず出す。黙ってよいのは「何も起きなかった」ときだけ。
if (!$quiet || $res['sent'] > 0 || $res['failed'] > 0) {
    echo "[{$stamp}] リマインダー送信: 成功 {$res['sent']} 件 / 失敗 {$res['failed']} 件"
       . (isDemoMode($s) ? '（デモモードのため実送信なし）' : '') . "\n";
}
exit($res['failed'] > 0 ? 1 : 0);

/**
 * data/ 配下の所有者を DB ファイルに揃える（root で実行されたときの後始末）。
 *
 * 【なぜ要るか】
 * cron を root で回すと、この実行で作られたファイル（mail.log・reminder_last_run・
 * SQLite の -journal / -wal）が **root 所有**になる。すると以後 Web 側（www-data）が
 * それらに書けなくなり、
 *   - 予約確定メールの記録が残らない（file_put_contents は @ 抑制なので無言で失敗する）
 *   - 間引きファイルを更新できず、アクセス契機の実行が毎リクエスト走る
 *   - 最悪、予約そのものが書き込めなくなる（journal を作れない）
 * という形で**静かに壊れる**。実際に検証環境で踏んだ（mail.log が root 所有になり、
 * 送信台帳には記録が残るのにログ本文だけ消えた）。
 *
 * chown は権限が無ければ false を返すだけなので、一般ユーザーで実行したときは何も起きない。
 * 根本的には「Webと同じユーザーで実行する」のが正しい（README 参照）。これはその保険。
 */
function alignDataOwnership(): void
{
    $dir = __DIR__ . '/../data';
    $owner = @fileowner($dir . '/database.sqlite');
    if ($owner === false) {
        return;
    }
    foreach ((array)@scandir($dir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path) && @fileowner($path) !== $owner) {
            @chown($path, $owner);
        }
    }
}
