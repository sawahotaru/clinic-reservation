<?php
// 公開予約カレンダー用の空き状況API（JSON）。
//   ?month=YYYY-MM      … その月の各日の状態を返す { days: { 'YYYY-MM-DD': 'free'|'full'|'closed'|'out' } }
//                         free=空きあり / full=空きなし(満・受付不可) / closed=休診 / out=予約可能期間外
//                         （過去日は返さない＝カレンダー側で無効表示）
//   ?date=YYYY-MM-DD    … その日の枠を返す { date, capacity, closed, slots:[{time,remaining}] }
require __DIR__ . '/../../src/core/db.php';
require __DIR__ . '/../../src/core/functions.php';
require_once __DIR__ . '/../../src/core/input.php';   // ?date[]=x のような配列注入を入口で潰す

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg     = slotConfig($pdo);
$today   = date('Y-m-d');
$limit   = date('Y-m-d', time() + $cfg['window'] * 86400); // 予約可能な最終日

// --- 単日: その日の枠一覧 ---
if (isset($_GET['date'])) {
    // ⚠ (string)$_GET['date'] は配列だと "Array" + 警告になる。警告が出力に混ざると
    //    JSON が壊れ、さらに header() を使う経路では "headers already sent" を誘発する。
    //    get_str() で配列を空文字に落とし、下の正規表現で 400 として弾く。
    $date = get_str('date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad date']);
        exit;
    }
    $closed = isClosedDay($date, $cfg, dayOverride($pdo, $date));
    $slots  = $closed ? [] : availableSlotsDetailed($pdo, $date, $cfg);
    echo json_encode([
        'date'     => $date,
        'capacity' => $cfg['capacity'],
        'closed'   => $closed,
        'slots'    => array_values($slots),
    ]);
    exit;
}

// --- 月単位: 各日の状態 ---
$month = get_str('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$daysInMonth = (int)date('t', strtotime($month . '-01'));

$days = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%s-%02d', $month, $d);
    if ($date < $today) {
        continue;                       // 過去日は返さない（カレンダー側で無効化）
    }
    if ($date > $limit) {
        $days[$date] = 'out';           // 予約可能期間外
        continue;
    }
    if (isClosedDay($date, $cfg, dayOverride($pdo, $date))) {
        $days[$date] = 'closed';        // 休診（日付指定＞曜日＞祝日）
        continue;
    }
    $slots = availableSlotsDetailed($pdo, $date, $cfg);
    $days[$date] = $slots ? 'free' : 'full';
}

echo json_encode(['month' => $month, 'days' => $days]);
