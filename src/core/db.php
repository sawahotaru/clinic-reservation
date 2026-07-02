<?php
// ===== SQLite への接続（共通） =====
// DBファイルは公開フォルダ(public)の外に置き、URLから直接ダウンロードされないようにする。

// ハニーポットで自動ブロック済みのIPは、DB接続する前にここで弾く（軽量・最優先）。
require_once __DIR__ . '/honeypot.php';
honeypot_shield();

date_default_timezone_set('Asia/Tokyo');

$dbPath = __DIR__ . '/../../data/database.sqlite';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== テーブルが無ければ作成（初回アクセス時に自動実行） =====
// ※ UNIQUE(date,time) は付けない。capacity（同時受付数）が2以上だと
//    同じ日時に複数件入るため。二重予約の防止は reserve.php 側で
//    BEGIN IMMEDIATE トランザクション＋残席チェックにより行う。
$pdo->exec('CREATE TABLE IF NOT EXISTS reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT NOT NULL,   -- 例: 2026-07-01
    time       TEXT NOT NULL,   -- 例: 10:00
    name       TEXT NOT NULL,
    phone      TEXT NOT NULL,
    email      TEXT,            -- 任意（入力があれば確認メールを送る）
    created_at TEXT NOT NULL
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS blocked_slots (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,         -- ブロックしたい日
    time TEXT                   -- 時刻指定の「1枠だけ」ふさぎ（NULLは旧仕様の終日。day_overrides へ移行）
)');

// 特定日の終日指定（優先順位レイヤー1）: status = 'open'(例外営業) | 'closed'(休業)
// 「日付指定 ＞ 曜日 ＞ 祝日」の最上位。日付に指定があれば曜日・祝日を上書きする。
$pdo->exec('CREATE TABLE IF NOT EXISTS day_overrides (
    date   TEXT PRIMARY KEY,
    status TEXT NOT NULL        -- open / closed
)');

// 旧 blocked_slots の「終日(time IS NULL)」行を day_overrides(closed) へ移行
$nullBlocks = $pdo->query('SELECT DISTINCT date FROM blocked_slots WHERE time IS NULL')->fetchAll(PDO::FETCH_COLUMN);
if ($nullBlocks) {
    $up = $pdo->prepare("INSERT OR IGNORE INTO day_overrides(date, status) VALUES(?, 'closed')");
    foreach ($nullBlocks as $d) {
        $up->execute([$d]);
    }
    $pdo->exec('DELETE FROM blocked_slots WHERE time IS NULL');
}

// ===== 既存DBの移行: email カラムが無ければ追加（古いDBでも壊さない） =====
$cols = $pdo->query("PRAGMA table_info(reservations)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('email', $cols, true)) {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN email TEXT');
}

// ===== 既存DBの移行: 旧 UNIQUE(date,time) 制約を撤去（capacity対応） =====
// SQLite は制約だけを後から落とせないので、テーブルを作り直してデータを移す。
$createSql = $pdo->query(
    "SELECT sql FROM sqlite_master WHERE type='table' AND name='reservations'"
)->fetchColumn();
if ($createSql && stripos($createSql, 'UNIQUE') !== false) {
    $pdo->exec('BEGIN IMMEDIATE');
    $pdo->exec('CREATE TABLE reservations_new (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        date       TEXT NOT NULL,
        time       TEXT NOT NULL,
        name       TEXT NOT NULL,
        phone      TEXT NOT NULL,
        email      TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('INSERT INTO reservations_new (id, date, time, name, phone, email, created_at)
                SELECT id, date, time, name, phone, email, created_at FROM reservations');
    $pdo->exec('DROP TABLE reservations');
    $pdo->exec('ALTER TABLE reservations_new RENAME TO reservations');
    $pdo->exec('COMMIT');
}

// ===== 設定テーブルの用意（店名・通知メール・送信方法など） =====
require_once __DIR__ . '/settings.php';
ensureSettings($pdo);

// ===== 管理アカウント・トークンの用意（初回に初期アカウント登録） =====
require_once __DIR__ . '/../auth/auth.php';
ensureAdmin($pdo);
