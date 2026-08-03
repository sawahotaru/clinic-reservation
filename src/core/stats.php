<?php
// ===== 予約の集計（管理画面の「統計」） =====
//
// 【この集計が答えられること／答えられないこと】
// reservations は**キャンセルで行ごと消える**設計なので、ここで数えられるのは
// 「いま予約として残っているもの」であって「これまでに発生した予約」ではない。
// キャンセル率や離脱の分析はこのテーブルからは原理的に出せない。
// 画面にもその旨を書いてある。数字が独り歩きするほうが、機能が無いことより害が大きい。
//
// 集計は SQLite の集約関数だけで行う（PHP側に全行を読み出さない）。件数が増えても
// メモリを食わず、レンタルサーバーでも同じように動く。

require_once __DIR__ . '/db.php';

/** 予約が1件も無ければ true（各集計は空配列を返すので、画面側の分岐用）。 */
function statsHasData(PDO $pdo): bool
{
    return (int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn() > 0;
}

/**
 * サマリー。今日を境に「これまで」と「これから」を分ける。
 * オーナーが最初に見たいのは合計ではなく「今月どうだったか」「先の予定は埋まっているか」。
 */
function statsSummary(PDO $pdo, string $today): array
{
    $thisMonth = substr($today, 0, 7);
    $lastMonth = date('Y-m', strtotime($today . ' -1 month'));

    $countWhere = function (string $where, array $args = []) use ($pdo): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE $where");
        $stmt->execute($args);
        return (int)$stmt->fetchColumn();
    };

    return [
        'total'      => (int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn(),
        'this_month' => $countWhere("substr(date, 1, 7) = ?", [$thisMonth]),
        'last_month' => $countWhere("substr(date, 1, 7) = ?", [$lastMonth]),
        'upcoming'   => $countWhere("date >= ?", [$today]),
        'last30'     => $countWhere("date < ? AND date >= ?", [$today, date('Y-m-d', strtotime($today . ' -30 day'))]),
        'this_month_label' => $thisMonth,
        'last_month_label' => $lastMonth,
    ];
}

/**
 * 月別の件数（直近 $months ヶ月・予約が無かった月も 0 で埋める）。
 *
 * 0 の月を落とすと折れ線が詰まって「ずっと横ばい」に見えてしまう。
 * 谷が谷として見えることに意味があるので、欠測は 0 として明示する。
 */
function statsByMonth(PDO $pdo, string $today, int $months = 12): array
{
    $from = date('Y-m-01', strtotime($today . ' -' . ($months - 1) . ' month'));
    $stmt = $pdo->prepare(
        'SELECT substr(date, 1, 7) AS ym, COUNT(*) AS n
           FROM reservations WHERE date >= ? GROUP BY ym'
    );
    $stmt->execute([$from]);
    $found = [];
    foreach ($stmt->fetchAll() as $row) {
        $found[$row['ym']] = (int)$row['n'];
    }

    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime($today . ' -' . $i . ' month'));
        $out[] = ['label' => $ym, 'count' => $found[$ym] ?? 0];
    }
    return $out;
}

/** 曜日別の件数（0=日 … 6=土）。予約の無い曜日も 0 で並べる。 */
function statsByWeekday(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT CAST(strftime('%w', date) AS INTEGER) AS w, COUNT(*) AS n
           FROM reservations GROUP BY w"
    )->fetchAll();
    $found = [];
    foreach ($rows as $row) {
        $found[(int)$row['w']] = (int)$row['n'];
    }

    $names = ['日', '月', '火', '水', '木', '金', '土'];
    $out = [];
    foreach ($names as $w => $name) {
        $out[] = ['label' => $name, 'count' => $found[$w] ?? 0, 'weekday' => $w];
    }
    return $out;
}

/**
 * 時間帯別の件数。枠設定を見直すときの一次資料で、この集計がいちばん実用に近い
 * （「夕方だけ埋まる」なら開始時刻を後ろへ、等）。
 * 実際に予約が入った時刻だけを、時刻順に返す。
 */
function statsByTime(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT time, COUNT(*) AS n FROM reservations GROUP BY time ORDER BY time'
    )->fetchAll();
    return array_map(fn($r) => ['label' => $r['time'], 'count' => (int)$r['n']], $rows);
}

/**
 * リピート状況。電話番号で名寄せする。
 *
 * 名前は表記ゆれ（漢字/かな・姓名の間の空白）で同一人物が割れるが、電話番号は
 * 予約時に必ず入る前提の項目で、桁も揃いやすい。完全ではないので画面では
 * 「おおよその目安」と書いてある。
 *
 * @return array{unique:int, repeat:int, once:int, top:array}
 */
function statsRepeat(PDO $pdo, int $topN = 5): array
{
    $rows = $pdo->query(
        "SELECT phone, COUNT(*) AS n, MAX(name) AS name, MAX(date) AS last_date
           FROM reservations
          WHERE phone IS NOT NULL AND trim(phone) <> ''
          GROUP BY phone"
    )->fetchAll();

    $unique = count($rows);
    $repeat = 0;
    foreach ($rows as $row) {
        if ((int)$row['n'] >= 2) {
            $repeat++;
        }
    }
    usort($rows, fn($a, $b) => (int)$b['n'] <=> (int)$a['n']);

    return [
        'unique' => $unique,
        'repeat' => $repeat,
        'once'   => $unique - $repeat,
        'top'    => array_slice($rows, 0, $topN),
    ];
}
