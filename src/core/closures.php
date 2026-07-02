<?php
// ===== 休業・枠ふさぎ（day_overrides / blocked_slots）モデル =====
// 管理コントローラ（admin/closures.php）の書き込み系CRUDをまとめた薄いデータ層。
//   day_overrides : 特定日の終日指定（'open'=例外営業 / 'closed'=休業）。優先順位レイヤー最上位。
//   blocked_slots : 開いている日の中の1枠ふさぎ（time指定）。
// ※ 枠計算で使う読み取り（dayOverride() / availableSlotsDetailed内のblocked参照）は
//    slot service（functions.php）側に残す。ここは管理画面用の一覧取得と更新を担当。

/** 特定日指定の一覧を日付順で返す。 */
function allDayOverrides(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM day_overrides ORDER BY date')->fetchAll();
}

/** 特定日を 'open'（例外営業）または 'closed'（休業）に指定（同じ日付は上書き）。不正日付は無視。 */
function setDayOverride(PDO $pdo, string $date, string $status): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return;
    }
    $status = $status === 'open' ? 'open' : 'closed';
    $up = $pdo->prepare('INSERT INTO day_overrides(date, status) VALUES(?, ?)
                         ON CONFLICT(date) DO UPDATE SET status = excluded.status');
    $up->execute([$date, $status]);
}

/** 特定日指定を解除。 */
function unsetDayOverride(PDO $pdo, string $date): void
{
    $stmt = $pdo->prepare('DELETE FROM day_overrides WHERE date = ?');
    $stmt->execute([$date]);
}

/** 1枠ふさぎ（時刻指定のみ）の一覧を返す。 */
function allBlockedSlots(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM blocked_slots WHERE time IS NOT NULL AND time <> '' ORDER BY date, time")->fetchAll();
}

/** 指定日に複数の枠をふさぐ（重複は追加しない）。不正な日付/時刻はスキップ。 */
function addBlockedSlots(PDO $pdo, string $date, array $times): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return;
    }
    $dup = $pdo->prepare('SELECT COUNT(*) FROM blocked_slots WHERE date = ? AND time = ?');
    $ins = $pdo->prepare('INSERT INTO blocked_slots (date, time) VALUES (?, ?)');
    foreach ($times as $bt) {
        $bt = trim((string)$bt);
        if (!preg_match('/^\d{2}:\d{2}$/', $bt)) {
            continue;
        }
        $dup->execute([$date, $bt]);             // 同じ内容が無いときだけ追加（重複防止）
        if ((int)$dup->fetchColumn() === 0) {
            $ins->execute([$date, $bt]);
        }
    }
}

/** 1枠ふさぎを解除（id指定）。 */
function removeBlockedSlot(PDO $pdo, $id): void
{
    $stmt = $pdo->prepare('DELETE FROM blocked_slots WHERE id = ?');
    $stmt->execute([$id]);
}
