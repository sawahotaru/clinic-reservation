<?php
// ===== ログイン試行回数の制限（ブルートフォース対策） =====
// 失敗が続いた「キー（既定=送信元IP）」を一定時間ロックする。ログイン成功でリセット。
// 記録は SQLite の login_throttle テーブル（ensureAdmin() で作成）。
// 外部依存なし・純PHP。前段の Basic 認証とは別レイヤの多層防御。

if (!defined('LOGIN_MAX_FAILS')) {
    define('LOGIN_MAX_FAILS',    5);   // 何回失敗でロックするか
    define('LOGIN_FAIL_WINDOW',  900); // 失敗カウントの有効期間（秒）= 15分
    define('LOGIN_LOCK_SECONDS', 900); // ロック継続時間（秒）= 15分
}

/** スロットリングのキー（送信元IP単位） */
function throttleKey(): string
{
    return 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** ロック中なら解除時刻(unixtime)、そうでなければ 0 を返す */
function throttleLockedUntil(PDO $pdo, string $key): int
{
    $st = $pdo->prepare('SELECT locked_until FROM login_throttle WHERE k = ?');
    $st->execute([$key]);
    $until = (int)($st->fetchColumn() ?: 0);
    return $until > time() ? $until : 0;
}

/** ログイン失敗を記録。窓内で閾値を超えたら locked_until を設定する */
function throttleRegisterFail(PDO $pdo, string $key): void
{
    $now = time();
    $st = $pdo->prepare('SELECT fails, first_at FROM login_throttle WHERE k = ?');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || ($now - (int)$row['first_at']) > LOGIN_FAIL_WINDOW) {
        $fails = 1;                 // 新規、または窓を過ぎたので仕切り直し
        $first = $now;
    } else {
        $fails = (int)$row['fails'] + 1;
        $first = (int)$row['first_at'];
    }
    $lockedUntil = ($fails >= LOGIN_MAX_FAILS) ? ($now + LOGIN_LOCK_SECONDS) : 0;

    $pdo->prepare('INSERT INTO login_throttle(k, fails, first_at, last_at, locked_until)
                   VALUES(?, ?, ?, ?, ?)
                   ON CONFLICT(k) DO UPDATE SET
                       fails        = excluded.fails,
                       first_at     = excluded.first_at,
                       last_at      = excluded.last_at,
                       locked_until = excluded.locked_until')
        ->execute([$key, $fails, $first, $now, $lockedUntil]);
}

/** ログイン成功時にキーのカウントを消す */
function throttleReset(PDO $pdo, string $key): void
{
    $pdo->prepare('DELETE FROM login_throttle WHERE k = ?')->execute([$key]);
}
