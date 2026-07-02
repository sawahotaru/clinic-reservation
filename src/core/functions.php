<?php
// ===== 空き枠の計算ロジック =====
// 枠そのものはDBに保存しない。営業ルール（settings）＋ 予約済み（reservations）
// ＋ 休み（blocked_slots）から、空き枠を毎回「計算」で導出する。
// ルールは管理画面（admin/slots.php）→ settings テーブルに保存され、ここで読む。

require_once __DIR__ . '/settings.php';

/**
 * 予約枠まわりの設定を数値化して取り出す（不正値はデフォルトへ丸める）。
 *   open/close   : 'HH:MM'
 *   interval     : 枠の開始間隔（刻み）分
 *   duration     : 1回の施術時間 分
 *   capacity     : 同じ時刻に受け付ける件数
 *   lead         : 最短リードタイム 分（今からこの分数より先の枠のみ受付）
 *   window       : 予約可能な日数（今日から何日先まで）
 */
function slotConfig(PDO $pdo): array
{
    $s = getSettings($pdo);

    // 定休日: '0,3' → [0,3]（0=日…6=土）。不正値は無視。
    $closed = [];
    foreach (preg_split('/[,\s]+/', (string)($s['closed_weekdays'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (is_numeric($w) && $w >= 0 && $w <= 6) {
            $closed[] = (int)$w;
        }
    }

    // 休憩時間（複数可）: 'HH:MM-HH:MM' をカンマ区切り。各々開始<終了のものだけ採用。
    // 旧 break_start/break_end（単一）しか無い古い設定も後方互換で取り込む。
    $breaksRaw = (string)($s['breaks'] ?? '');
    if ($breaksRaw === '' && ($s['break_start'] ?? '') !== '' && ($s['break_end'] ?? '') !== '') {
        $breaksRaw = $s['break_start'] . '-' . $s['break_end'];
    }
    $breaks = parseBreaks($breaksRaw);

    // 曜日別モードの設定（0..6 をキーにしたJSON）。不正なら空配列。
    $weekday = json_decode((string)($s['weekday_config'] ?? ''), true);
    if (!is_array($weekday)) {
        $weekday = [];
    }

    return [
        'open'      => preg_match('/^\d{1,2}:\d{2}$/', $s['open_time']  ?? '') ? $s['open_time']  : '10:00',
        'close'     => preg_match('/^\d{1,2}:\d{2}$/', $s['close_time'] ?? '') ? $s['close_time'] : '18:00',
        'interval'  => max(5, (int)($s['slot_minutes']        ?? 30)),
        'duration'  => max(5, (int)($s['duration_minutes']    ?? 30)),
        'capacity'  => max(1, (int)($s['capacity']            ?? 1)),
        'lead'      => max(0, (int)($s['lead_time_minutes']   ?? 0)),
        'window'    => max(0, (int)($s['booking_window_days'] ?? 30)),
        'closed'    => $closed,
        'breaks'    => $breaks,                                  // [['start'=>'12:00','end'=>'13:00'], ...]
        'holidays'  => ((string)($s['close_on_holidays'] ?? '0')) === '1',
        'mode'      => (($s['schedule_mode'] ?? 'uniform') === 'per_weekday') ? 'per_weekday' : 'uniform',
        'weekday'   => $weekday,                                 // 生のJSON（曜日 => {closed,open,close,...}）
    ];
}

/**
 * ある曜日（$w=0..6）に適用される枠ルールを解決して返す。
 *   一律モード   : 全曜日とも slotConfig の値。closed は定休日リストに含まれるか。
 *   曜日別モード : weekday[$w] の値。空欄の項目は一律値にフォールバック。
 * 返り値: ['open','close','interval','duration','capacity','breaks'(parsed),'closed_today'(bool)]
 */
function weekdayCfg(array $cfg, int $w): array
{
    if (($cfg['mode'] ?? 'uniform') !== 'per_weekday') {
        return [
            'open'         => $cfg['open'],
            'close'        => $cfg['close'],
            'interval'     => $cfg['interval'],
            'duration'     => $cfg['duration'],
            'capacity'     => $cfg['capacity'],
            'breaks'       => $cfg['breaks'],
            'closed_today' => in_array($w, $cfg['closed'] ?? [], true),
        ];
    }

    // 曜日別: weekday[$w]（または "$w"）。空欄/不正は一律値へフォールバック。
    $e = $cfg['weekday'][$w] ?? $cfg['weekday'][(string)$w] ?? [];
    $e = is_array($e) ? $e : [];
    $hhmm = fn($v, $fallback) => (is_string($v) && preg_match('/^\d{1,2}:\d{2}$/', $v)) ? $v : $fallback;
    $posInt = fn($v, $min, $fallback) => (is_numeric($v) && (int)$v >= $min) ? (int)$v : $fallback;

    return [
        'open'         => $hhmm($e['open']  ?? null, $cfg['open']),
        'close'        => $hhmm($e['close'] ?? null, $cfg['close']),
        'interval'     => $posInt($e['slot_minutes']     ?? null, 5, $cfg['interval']),
        'duration'     => $posInt($e['duration_minutes'] ?? null, 5, $cfg['duration']),
        'capacity'     => $posInt($e['capacity']         ?? null, 1, $cfg['capacity']),
        'breaks'       => parseBreaks((string)($e['breaks'] ?? '')),
        'closed_today' => !empty($e['closed']),
    ];
}

/**
 * 指定日付に適用される枠ルールを解決して返す（曜日を見て weekdayCfg を引く）。
 * lead / window / holidays（全曜日共通）も載せて返す。
 */
function dayCfg(array $cfg, string $date): array
{
    $w  = (int)date('w', strtotime($date));
    $wc = weekdayCfg($cfg, $w);
    $wc['lead']     = $cfg['lead'];
    $wc['window']   = $cfg['window'];
    $wc['holidays'] = $cfg['holidays'];
    return $wc;
}

/** 'HH:MM-HH:MM,HH:MM-HH:MM' を [['start'=>,'end'=>], ...] に。開始<終了のみ採用。 */
function parseBreaks(string $raw): array
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $pair) {
        if (preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $pair, $m)
            && strtotime($m[1]) < strtotime($m[2])) {
            $out[] = ['start' => $m[1], 'end' => $m[2]];
        }
    }
    return $out;
}

/** 同梱の祝日表（data/holidays.csv: 'Y-m-d,名称'）を読み込み、Y-m-d をキーにした連想配列で返す（キャッシュ）。 */
function holidayMap(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $path = __DIR__ . '/../../data/holidays.csv';
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$d, $name] = array_pad(explode(',', $line, 2), 2, '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $map[$d] = $name;
            }
        }
    }
    return $map;
}

/** その日付が祝日か。 */
function isHoliday(string $date): bool
{
    return isset(holidayMap()[$date]);
}

/** 特定日の終日指定を返す: 'open'（例外営業） | 'closed'（休業） | null（指定なし）。 */
function dayOverride(PDO $pdo, string $date): ?string
{
    $stmt = $pdo->prepare('SELECT status FROM day_overrides WHERE date = ?');
    $stmt->execute([$date]);
    $v = $stmt->fetchColumn();
    return ($v === 'open' || $v === 'closed') ? $v : null;
}

/**
 * その日付が休診日かを「日付指定 ＞ 曜日 ＞ 祝日」の優先順位で判定する。
 * 上位で当たれば下位は見ない（$override に日付指定の結果を渡す）。
 *   1. 日付指定: 'open'→営業(false) / 'closed'→休業(true)
 *   2. 定休日の曜日
 *   3. （祝日トグルON時の）祝日CSV
 */
function isClosedDay(string $date, array $cfg, ?string $override = null): bool
{
    if ($override === 'open')   return false; // 例外的に営業（曜日・祝日を上書き）
    if ($override === 'closed') return true;  // 特定日休業

    // 定休（一律=定休日リスト / 曜日別=その曜日のclosed）は dayCfg が解決する。
    if (dayCfg($cfg, $date)['closed_today']) {
        return true;
    }
    if (!empty($cfg['holidays']) && isHoliday($date)) {
        return true;
    }
    return false;
}

/**
 * 営業ルールから「枠の開始時刻」を全部生成する。
 * 施術時間（duration）が閉店までに収まる枠だけを、間隔（interval）刻みで出す。
 * 例: open10:00 close18:00 interval30 duration30 → 10:00,10:30,…17:30
 */
function generateSlots(array $cfg): array
{
    $slots = [];
    $start = strtotime($cfg['open']);
    $end   = strtotime($cfg['close']);
    if ($start === false || $end === false || $start >= $end) {
        return $slots;
    }
    $dur  = $cfg['duration'] * 60;
    $step = $cfg['interval'] * 60;

    // 休憩時間（複数）に重なる枠は除外（施術時間ぶんが休憩帯に少しでもかかれば塞ぐ）
    $breaks = [];
    foreach ($cfg['breaks'] ?? [] as $b) {
        $breaks[] = ['s' => strtotime($b['start']), 'e' => strtotime($b['end'])];
    }

    for ($t = $start; $t + $dur <= $end; $t += $step) {
        $hit = false;
        foreach ($breaks as $b) {
            if ($t < $b['e'] && ($t + $dur) > $b['s']) { // [t, t+dur) が休憩 [s, e) と重なる
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $slots[] = date('H:i', $t);
        }
    }
    return $slots;
}

/**
 * 指定日の「空き枠」を [ ['time'=>'10:00','remaining'=>2], ... ] で返す。
 * 全枠から「定員に達した時刻」「ブロック時刻」を除外し、
 * さらに最短リードタイム・予約可能期間でも絞る。
 */
function availableSlotsDetailed(PDO $pdo, string $date, ?array $cfg = null): array
{
    $cfg = $cfg ?? slotConfig($pdo);
    // 終日の休診判定（日付指定 ＞ 曜日 ＞ 祝日の優先順位）
    if (isClosedDay($date, $cfg, dayOverride($pdo, $date))) {
        return [];
    }
    // その日付に適用される枠ルール（曜日別なら曜日ごとの値）を解決
    $day = dayCfg($cfg, $date);
    $all = generateSlots($day);

    // 予約済み件数（時刻ごと）: time => 件数
    $stmt = $pdo->prepare('SELECT time, COUNT(*) AS c FROM reservations WHERE date = ? GROUP BY time');
    $stmt->execute([$date]);
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 個別にふさいだ時刻（開いている日の中の1枠ふさぎ）
    $stmt = $pdo->prepare("SELECT time FROM blocked_slots WHERE date = ? AND time IS NOT NULL AND time <> ''");
    $stmt->execute([$date]);
    $blocked = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $result = [];
    foreach ($all as $time) {
        if (isset($blocked[$time]))               continue;
        $remaining = $day['capacity'] - (int)($counts[$time] ?? 0);
        if ($remaining <= 0)                      continue;
        if (!isBookable($date, $time, $day))      continue;
        $result[] = ['time' => $time, 'remaining' => $remaining];
    }
    return $result;
}

/** 指定日の空き枠（時刻だけの配列）。従来の呼び出し互換。 */
function availableSlots(PDO $pdo, string $date, ?array $cfg = null): array
{
    return array_map(fn($s) => $s['time'], availableSlotsDetailed($pdo, $date, $cfg));
}

/** その日時が「今から予約可能」か（最短リードタイム・予約可能期間でチェック）。 */
function isBookable(string $date, string $time, array $cfg): bool
{
    $now   = time();
    $start = strtotime("$date $time");
    if ($start === false) {
        return false;
    }
    // 直前すぎる枠は不可（今 + lead 分 より後の開始のみ）
    if ($start < $now + $cfg['lead'] * 60) {
        return false;
    }
    // 予約可能期間: 今日の終わり + window 日 まで
    $limit = strtotime(date('Y-m-d', $now) . ' 23:59:59') + $cfg['window'] * 86400;
    if ($start > $limit) {
        return false;
    }
    return true;
}

/** その日時の残席数（capacity − 予約数）。予約確定の最終チェックに使う。capacityは曜日別を解決。 */
function remainingCapacity(PDO $pdo, string $date, string $time, array $cfg): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE date = ? AND time = ?');
    $stmt->execute([$date, $time]);
    return dayCfg($cfg, $date)['capacity'] - (int)$stmt->fetchColumn();
}
