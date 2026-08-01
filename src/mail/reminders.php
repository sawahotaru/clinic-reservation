<?php
require_once __DIR__ . '/notify.php';

// ===== 来院前リマインダー =====
//
// 【いつ送るか】
// 予約日 D・設定「N日前 / H時」に対し、送信開始時刻を **(D - N日) の H:00** と定める。
// 「今がそれを過ぎていて、まだ予約時刻が来ていない」予約が対象。
// 幅のある「窓」ではなく1点の基準時刻にしてあるのは、実行が遅れても取りこぼさないため
// （18:00に実行できなくても、翌朝の実行で当日ぶんが送られる。窓方式だと黙って漏れる）。
//
// 【二重送信をどう防ぐか】
// reminder_log の主キーが reservation_id。送信の直前に INSERT OR IGNORE で行を立て、
// **1行入った側だけが送信する**（＝送信権の取得）。cron と画面アクセス契機が同時に走っても、
// 片方は 0 行になって何もしない。送信結果は後から UPDATE で書き戻す。
//
// 【実行のされ方】
//   1) cron / タスクスケジューラ → `php bin/send-reminders.php`（本命）
//   2) 公開ページのアクセス契機 → remindersTick()（cron を用意できない環境向けの保険）
//   3) 管理画面の「今すぐ送信」ボタン

/** 1回の実行で送る上限。画面アクセス契機で長時間ブロックさせないための安全弁。 */
const REMINDER_MAX_PER_RUN = 20;

/** アクセス契機の実行間隔（秒）。毎リクエスト走らせないための間引き。 */
const REMINDER_TICK_INTERVAL = 600;

/** 設定を正規化して返す */
function reminderConfig(array $s): array
{
    return [
        'enabled' => ($s['reminder_enabled'] ?? '0') === '1',
        'days'    => max(0, min(30, (int)($s['reminder_days_before'] ?? 1))),
        'hour'    => max(0, min(23, (int)($s['reminder_send_hour'] ?? 18))),
    ];
}

/** 予約日 $date に対する送信開始時刻（Unix秒） */
function reminderSendAt(string $date, array $cfg): int
{
    return strtotime($date . ' ' . sprintf('%02d:00', $cfg['hour'])) - $cfg['days'] * 86400;
}

/**
 * まだ送っていない送信対象の予約を返す（時刻順）。
 *
 * 除外するもの:
 *  - メール未入力（送り先が無い）
 *  - 予約時刻が既に過ぎているもの（終わった予約に前日通知を送らない）
 *  - **送信開始時刻より後に入った予約**。直前予約は確定メールが出たばかりで、
 *    そこへ即リマインダーを重ねても意味が無い（むしろ二重で届いたように見える）。
 */
function dueReminders(PDO $pdo, array $s, ?int $now = null): array
{
    $cfg = reminderConfig($s);
    $now = $now ?? time();

    // 送信開始時刻を過ぎている ＝ 予約日 <= 今日 + N日。索引の効く範囲に絞ってから精査する。
    $windowEnd = date('Y-m-d', $now + $cfg['days'] * 86400);
    $today     = date('Y-m-d', $now);

    $st = $pdo->prepare(
        "SELECT r.id, r.date, r.time, r.name, r.phone, r.email, r.created_at
           FROM reservations r
          WHERE r.email IS NOT NULL AND TRIM(r.email) <> ''
            AND r.date >= :today AND r.date <= :windowEnd
            AND NOT EXISTS (SELECT 1 FROM reminder_log l WHERE l.reservation_id = r.id)
          ORDER BY r.date, r.time"
    );
    $st->execute([':today' => $today, ':windowEnd' => $windowEnd]);

    $due = [];
    foreach ($st->fetchAll() as $r) {
        $sendAt = reminderSendAt($r['date'], $cfg);
        if ($now < $sendAt) {
            continue;                                   // まだ送信開始時刻に達していない
        }
        if (strtotime($r['date'] . ' ' . $r['time']) <= $now) {
            continue;                                   // 予約時刻が過ぎている
        }
        if (!empty($r['created_at']) && strtotime($r['created_at']) >= $sendAt) {
            continue;                                   // 送信開始時刻より後に入った予約
        }
        $due[] = $r;
    }
    return $due;
}

/**
 * 送信対象へ1通ずつ送る。戻り値は ['sent'=>int, 'failed'=>int, 'skipped'=>bool]。
 * 'skipped' は「リマインダーが無効」のときだけ true。
 */
function sendDueReminders(PDO $pdo, ?int $now = null): array
{
    $s   = getSettings($pdo);
    $cfg = reminderConfig($s);
    if (!$cfg['enabled']) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => true];
    }

    $claim  = $pdo->prepare('INSERT OR IGNORE INTO reminder_log(reservation_id, sent_at, result)
                             VALUES(?, ?, ?)');
    $finish = $pdo->prepare('UPDATE reminder_log SET result = ? WHERE reservation_id = ?');

    $sent = 0;
    $failed = 0;
    foreach (dueReminders($pdo, $s, $now) as $r) {
        if ($sent + $failed >= REMINDER_MAX_PER_RUN) {
            break;                                      // 残りは次の実行で
        }
        // 送信権の取得。既に誰かが取っていれば 0 行 ＝ このプロセスは送らない。
        $claim->execute([$r['id'], date('Y-m-d H:i:s'), 'sending']);
        if ($claim->rowCount() === 0) {
            continue;
        }

        [$subject, $body] = reminderMessage($s, $r);
        [$ok, $err] = sendMail($s, $r['email'], $subject, $body, null, 'reminder');

        $finish->execute([$ok ? 'ok' : ('ng: ' . $err), $r['id']]);
        $ok ? $sent++ : $failed++;
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
}

/** リマインダー本文。予約確定メールと同じ体裁に揃える。 */
function reminderMessage(array $s, array $r): array
{
    $shop = $s['shop_name'];
    $when = "{$r['date']} {$r['time']}";
    $subject = "【{$shop}】ご予約日が近づいています（{$when}）";
    $body =
        "{$r['name']} 様\n\n" .
        "ご予約の日が近づいてまいりましたので、お知らせいたします。\n\n" .
        "──────────\n" .
        "予約番号 : {$r['id']}\n日時　　 : {$when}\n" .
        "お名前　 : {$r['name']} 様\n" .
        "──────────\n\n" .
        "お気をつけてお越しください。\n" .
        "※このメールは送信専用です。ご変更・キャンセルはお電話でお願いいたします。\n\n{$shop}\n";
    return [$subject, $body];
}

/**
 * 公開ページのアクセス契機で回すための入口（cron を用意できない環境の保険）。
 * 間引きファイルで実行間隔をあけ、失敗しても**画面には一切影響させない**。
 *
 * ⚠️ 必ずページ出力の**後**に呼ぶこと。送信中に閲覧者を待たせないため。
 */
function remindersTick(PDO $pdo): void
{
    $stamp = __DIR__ . '/../../data/reminder_last_run';
    $last  = is_file($stamp) ? (int)@file_get_contents($stamp) : 0;
    if (time() - $last < REMINDER_TICK_INTERVAL) {
        return;
    }
    // 先に印を更新する。送信中に別リクエストが来ても走らせないため
    // （送信権は reminder_log が握っているので、これは負荷対策）。
    @file_put_contents($stamp, (string)time(), LOCK_EX);

    try {
        sendDueReminders($pdo);
    } catch (Throwable $e) {
        @file_put_contents(
            __DIR__ . '/../../data/mail.log',
            '[' . date('Y-m-d H:i:s') . "] type=reminder tick failed: " . $e->getMessage() . "\n",
            FILE_APPEND
        );
    }
}
