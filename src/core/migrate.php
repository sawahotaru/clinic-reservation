<?php
// ===== スキーマ移行（最小のマイグレーション・ランナー） =====
//
// 【なぜ要るか】
// このアプリは `CREATE TABLE IF NOT EXISTS` でテーブルを用意している。これは
// 「まだ無ければ作る」だけなので、**後から CREATE 文に列を足しても、既に動いている
// DBには一生反映されない**。新規設置と既存設置で中身が食い違い、しかも誰も気づけない。
// （同じ構造の問題が ec-api で実際に起き、期限切れ処理が本番だけ落ち続けていた）
//
// settings テーブルは key-value なので新しい設定キーは勝手に増えて安全だが、
// 上記の固定列テーブル（reservations / blocked_slots / day_overrides /
// auth_tokens / login_throttle）は安全ではない。そこで版番号を持たせ、
// 「まだ当てていない手順だけを順に当てる」形にする。
//
// 【方針】
// - 版番号は settings の 'schema_version'（既定 0 ＝ 一度も走っていない）。
// - 各手順は**それ自体が冪等**（何度流しても同じ結果）であること。版番号は
//   「毎リクエストで PRAGMA を舐めない」ための最適化であって、正しさの拠り所にしない。
// - 外部ライブラリなし・純PHP。レンタルサーバーでもそのまま動く。
//
// 【手順を足すとき】
//   1. migrationSteps() に次の番号を足す
//   2. 新規DBと既存DBの両方で通ることを確かめる（両方に当たるのが前提）
//   3. 列の追加は必ず ALTER TABLE ADD COLUMN で書く。
//      CREATE TABLE 文だけ直しても、既に存在するDBには永久に届かない

/** 適用する手順。キー＝版番号（昇順）、値＝PDO を受け取る無名関数。 */
function migrationSteps(): array
{
    return [
        // --- 1: 旧バージョンからの積み残しを整理する ---
        // もともと db.php が毎リクエスト実行していたもの。いずれも条件付きで、
        // 対象でなければ何もしない。整理済みのDBに当てても無害。
        1 => function (PDO $pdo): void {
            // (a) 旧 blocked_slots の「終日ブロック(time IS NULL)」を day_overrides へ移す
            $nullBlocks = $pdo->query('SELECT DISTINCT date FROM blocked_slots WHERE time IS NULL')
                              ->fetchAll(PDO::FETCH_COLUMN);
            if ($nullBlocks) {
                $up = $pdo->prepare("INSERT OR IGNORE INTO day_overrides(date, status) VALUES(?, 'closed')");
                foreach ($nullBlocks as $d) {
                    $up->execute([$d]);
                }
                $pdo->exec('DELETE FROM blocked_slots WHERE time IS NULL');
            }

            // (b) email 列が無い古いDBに追加する
            $cols = $pdo->query('PRAGMA table_info(reservations)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('email', $cols, true)) {
                $pdo->exec('ALTER TABLE reservations ADD COLUMN email TEXT');
            }

            // (c) 旧 UNIQUE(date,time) 制約を撤去する。capacity（同時受付数）が2以上だと
            //     同じ日時に複数件入るため、この制約が残っていると予約が弾かれてしまう。
            //     SQLite は制約だけを後から落とせないので、作り直してデータを移す。
            $createSql = $pdo->query(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='reservations'"
            )->fetchColumn();
            if ($createSql && stripos($createSql, 'UNIQUE') !== false) {
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
            }
        },

        // --- 2: 検索用インデックス ---
        // 空き状況の計算は「その日の予約を数える」ので、日付での絞り込みが毎回走る
        // （トップページ・カレンダーAPI・予約確定時の残席チェック）。
        // 予約が積み上がる運用では全件走査になるので、ここだけは張っておく。
        2 => function (PDO $pdo): void {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservations_date_time ON reservations(date, time)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blocked_slots_date     ON blocked_slots(date)');
        },

        // --- 3: リマインダーの送信済み台帳 ---
        // 「1予約につき1通だけ」を保証する要。主キーが reservation_id なので、
        // INSERT OR IGNORE が成功した側だけが送信する（＝送信権の取得）形にでき、
        // 同時に2つの実行経路（cron と 画面アクセス契機）が走っても二重送信しない。
        //
        // 予約をキャンセルすると reservations の行は削除されるので、ここに孤児の行が残る。
        // 消さずに放置してよい: reservations.id は AUTOINCREMENT ＝ SQLite は削除済みIDを
        // **再利用しない**ので、別人の予約が「送信済み」と誤判定されることはない。
        3 => function (PDO $pdo): void {
            $pdo->exec('CREATE TABLE IF NOT EXISTS reminder_log (
                reservation_id INTEGER PRIMARY KEY,
                sent_at        TEXT NOT NULL,
                result         TEXT
            )');
        },
    ];
}

/** 現在のスキーマ版（未設定なら 0）。 */
function schemaVersion(PDO $pdo): int
{
    $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute(['schema_version']);
    $v = $st->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

/**
 * 未適用の手順を順に当てる。settings テーブルが存在してから呼ぶこと。
 * 手順ごとにトランザクションで包み、失敗したらその手順を丸ごと巻き戻して版番号も上げない
 * （次のアクセスで再挑戦する）。
 */
function migrateSchema(PDO $pdo): void
{
    $current = schemaVersion($pdo);
    $save = $pdo->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value');

    foreach (migrationSteps() as $version => $step) {
        if ($version <= $current) {
            continue;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $step($pdo);
            $save->execute(['schema_version', (string)$version]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
