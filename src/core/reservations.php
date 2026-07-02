<?php
// ===== 予約（reservations）モデル =====
// コントローラ（admin/index.php・reserve.php）から生SQLを排除するための薄いデータ層。
// 枠計算サービス（functions.php）の remainingCapacity を使うため読み込んでおく。

require_once __DIR__ . '/functions.php';

/** 全予約を日付・時刻順で返す（管理の予約一覧）。 */
function allReservations(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM reservations ORDER BY date, time')->fetchAll();
}

/** 予約を1件削除（管理のキャンセル）。 */
function deleteReservation(PDO $pdo, $id): void
{
    $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * capacity（同時受付数）を尊重した二重予約防止つきで予約を作成する。
 * 書き込みロックを先取りする BEGIN IMMEDIATE で「残席チェック→確定」を直列化し、
 * ほぼ同時アクセスでも定員超過しないようにする。
 *   $r  : ['date','time','name','phone','email']
 *   戻り: 新しい予約ID。満席なら null。DBエラーは PDOException を呼び出し元へ送出。
 */
function createReservation(PDO $pdo, array $r, array $cfg): ?int
{
    $inTx = false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $inTx = true;

        if (remainingCapacity($pdo, $r['date'], $r['time'], $cfg) <= 0) {
            $pdo->exec('ROLLBACK');
            return null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO reservations (date, time, name, phone, email, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$r['date'], $r['time'], $r['name'], $r['phone'], $r['email'], date('Y-m-d H:i:s')]);
        $id = (int)$pdo->lastInsertId();
        $pdo->exec('COMMIT');
        return $id;
    } catch (PDOException $e) {
        if ($inTx) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}
