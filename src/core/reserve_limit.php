<?php
// ===== 公開予約の受付上限 =====
// 予約の受付件数と、確認メールの通数に上限を設ける。
// 記録は reserve_events テーブル（migrate.php の手順 4 で作成）。
// 値は運用に合わせて、ここの定数で変える。

require_once __DIR__ . '/secrets.php';

/** 件数を数える期間（秒） */
const RESERVE_WINDOW = 3600;
/** 同じ送信元から、RESERVE_WINDOW 秒のあいだに受け付ける件数 */
const RESERVE_LIMIT_PER_IP = 5;
/** 全体で、RESERVE_WINDOW 秒のあいだに受け付ける件数。超えたら受付を止める */
const RESERVE_LIMIT_TOTAL = 30;
/** 同じ電話番号またはメールアドレスで持てる、これから先の予約の件数 */
const RESERVE_LIMIT_PER_CONTACT = 3;
/** 同じ宛先へ、RESERVE_MAIL_WINDOW 秒のあいだに送る確認メールの通数 */
const RESERVE_MAIL_PER_ADDRESS = 3;
/** 確認メールの通数を数える期間（秒） */
const RESERVE_MAIL_WINDOW = 86400;
/** 記録を残す期間（秒）。これより古い行は、記録のたびに消す */
const RESERVE_EVENT_KEEP = 172800;

/** 記録に使うキー（値そのものは列に書かない）。鍵があれば HMAC、無ければ SHA-256 */
function reserveKey(string $value): string
{
    $value = strtolower(trim($value));
    $key   = secretKey();
    return $key === null ? hash('sha256', $value) : hash_hmac('sha256', $value, $key);
}

/** 送信元のまとまり。IPv6 は先頭 64 ビット、IPv4（IPv4 射影の IPv6 を含む）はアドレス全体 */
function reserveIpGroup(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return $ip;
    }
    $bin = inet_pton($ip);
    if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        return (string)inet_ntop(substr($bin, 12));
    }
    return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** 送信元の記録用のキー */
function reserveIpKey(string $ip): string
{
    return reserveKey(reserveIpGroup($ip));
}

/** 電話番号の数字だけ（全角の数字は半角にする） */
function reservePhoneDigits(string $phone): string
{
    $phone = strtr($phone, ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
                            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9']);
    return (string)preg_replace('/[^0-9]/', '', $phone);
}

/** $since より後の記録の件数。$key が null なら種類だけで数える */
function reserveEventCount(PDO $pdo, string $kind, ?string $key, int $since): int
{
    if ($key === null) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM reserve_events WHERE kind = ? AND at > ?');
        $st->execute([$kind, $since]);
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM reserve_events WHERE kind = ? AND k = ? AND at > ?');
        $st->execute([$kind, $key, $since]);
    }
    return (int)$st->fetchColumn();
}

/** 記録を 1 行足し、残す期間を過ぎた行を消す */
function reserveRecord(PDO $pdo, string $kind, string $key, ?int $now = null): void
{
    $now = $now ?? time();
    $pdo->prepare('INSERT INTO reserve_events (kind, k, at) VALUES (?, ?, ?)')
        ->execute([$kind, $key, $now]);
    $pdo->prepare('DELETE FROM reserve_events WHERE at < ?')
        ->execute([$now - RESERVE_EVENT_KEEP]);
}

/**
 * これから先の予約のうち、電話番号かメールアドレスが一致する件数。
 * 電話は数字だけ、メールは小文字にして比べる。空の値では照合しない。
 */
function futureReservationsByContact(PDO $pdo, string $phone, string $email, ?int $now = null): int
{
    $now    = $now ?? time();
    $today  = date('Y-m-d', $now);
    $digits = reservePhoneDigits($phone);
    $mail   = strtolower(trim($email));
    if ($digits === '' && $mail === '') {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT phone, email FROM reservations
          WHERE date > ? OR (date = ? AND time >= ?)'
    );
    $st->execute([$today, $today, date('H:i', $now)]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $samePhone = $digits !== '' && reservePhoneDigits((string)$row['phone']) === $digits;
        $sameMail  = $mail !== '' && strtolower(trim((string)$row['email'])) === $mail;
        if ($samePhone || $sameMail) {
            $n++;
        }
    }
    return $n;
}

/**
 * 予約を受け付けられないときの理由を返す。受け付けられるなら ''。
 *   'total'   … 全体の件数が上限に達している
 *   'ip'      … 同じ送信元の件数が上限に達している
 *   'contact' … 同じ連絡先の、これから先の予約が上限に達している
 *   'error'   … 判定中に DB の例外が起きた
 */
function reserveLimitReason(PDO $pdo, string $ip, string $phone, string $email, ?int $now = null): string
{
    $now   = $now ?? time();
    $since = $now - RESERVE_WINDOW;
    try {
        if (reserveEventCount($pdo, 'reserve', null, $since) >= RESERVE_LIMIT_TOTAL) {
            return 'total';
        }
        if (reserveEventCount($pdo, 'reserve', reserveIpKey($ip), $since) >= RESERVE_LIMIT_PER_IP) {
            return 'ip';
        }
        if (futureReservationsByContact($pdo, $phone, $email, $now) >= RESERVE_LIMIT_PER_CONTACT) {
            return 'contact';
        }
    } catch (PDOException $e) {
        return 'error';
    }
    return '';
}

/** 確認メールを送ってよいか（同じ宛先への通数の上限） */
function reserveMailAllowed(PDO $pdo, string $email, ?int $now = null): bool
{
    $now = $now ?? time();
    return reserveEventCount($pdo, 'mail', reserveKey($email), $now - RESERVE_MAIL_WINDOW)
        < RESERVE_MAIL_PER_ADDRESS;
}

/** 受付停止の知らせを送るときなら、記録して true（RESERVE_WINDOW 秒に 1 回） */
function reserveAlertDue(PDO $pdo, ?int $now = null): bool
{
    $now = $now ?? time();
    if (reserveEventCount($pdo, 'alert', null, $now - RESERVE_WINDOW) > 0) {
        return false;
    }
    reserveRecord($pdo, 'alert', '', $now);
    return true;
}
