<?php
// ===== 設定（DB保存・管理画面から編集） =====
// 店名・通知先メール・送信方法などを settings テーブルに保存する。
// 管理画面の「設定」ページから編集できる（ファイルを触らなくてよい）。

/** 設定のデフォルト値（DBに無い項目はこの値を使う） */
function settingDefaults(): array
{
    return [
        'shop_name'    => 'サンプル整体院', // 店名（メール差出人名・本文）
        'notify_email' => '',               // お店（院長）が予約通知を受け取るアドレス。空なら送らない
        'mail_method'  => 'server',          // 送信方法: 'server'(サーバー標準mail) | 'gmail'(SMTP)
        'mail_from'    => 'no-reply@example.com', // サーバー標準送信時の送信元アドレス
        'smtp_user'    => '',               // Gmailアドレス（gmail選択時）
        'smtp_pass'    => '',               // Gmailアプリパスワード（gmail選択時）
        'demo_mode'    => '0',              // '1'なら実際には送信せず記録のみ（展示・お試し用）

        // ===== 予約枠（管理画面「予約枠」で編集） =====
        // 枠はDBに保存せず、これらのルールから毎回「計算」で空き枠を導出する。
        'open_time'           => '10:00', // 営業開始
        'close_time'          => '18:00', // 営業終了（施術がこの時刻までに終わる枠のみ出す）
        'slot_minutes'        => '30',    // 枠を出す「開始間隔（刻み）」分
        'duration_minutes'    => '30',    // 1回の「施術時間」分（間隔と分けられる: 60分施術を30分刻みで出す等）
        'capacity'            => '1',     // 同じ時刻に受け付ける件数（ベッド/スタッフ数）
        'lead_time_minutes'   => '0',     // 最短リードタイム: 今からこの分数より先の枠だけ受付（直前予約の締切）
        'booking_window_days' => '30',    // 何日先まで予約可能か
        'closed_weekdays'     => '',      // 定休日の曜日。0=日…6=土 をカンマ区切り（例 '0,3'=日・水）。空なら無し
        'breaks'              => '',      // 休憩時間（複数可）。'HH:MM-HH:MM' をカンマ区切り（例 '12:00-13:00,15:00-15:15'）
        'close_on_holidays'   => '0',     // '1'なら祝日（data/holidays.csv）を終日休診にする
        // 営業時間などを「一律」にするか「曜日別」にするか。
        'schedule_mode'       => 'uniform', // 'uniform'(全曜日共通) | 'per_weekday'(曜日ごと)
        // 曜日別モードの設定。0=日…6=土 をキーにしたJSON。各曜日は
        //   {closed:bool, open, close, slot_minutes, duration_minutes, capacity, breaks}
        // 空欄の項目は一律（上記）の値にフォールバックする。
        'weekday_config'      => '',
        // 予約フォームの連絡先必須ルール（名前は常に必須）:
        //   'either'(電話かメールのどちらか) | 'phone'(電話必須) | 'email'(メール必須) | 'both'(両方)
        'contact_requirement' => 'either',
        // 旧: break_start / break_end（単一休憩）。breaks へ統合。後方互換で slotConfig が読む。
        'break_start'         => '',
        'break_end'           => '',
    ];
}

/** settings テーブルを用意し、未登録のデフォルト値を補完する */
function ensureSettings(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach (settingDefaults() as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

/** すべての設定を連想配列で取得（デフォルトで穴埋め） */
function getSettings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge(settingDefaults(), $rows ?: []);
}

/** 設定を保存（既知のキーのみ。upsert） */
function saveSettings(PDO $pdo, array $values): void
{
    $allowed = settingDefaults();
    $stmt = $pdo->prepare(
        'INSERT INTO settings(key, value) VALUES(?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    foreach ($values as $k => $v) {
        if (array_key_exists($k, $allowed)) {
            $stmt->execute([$k, (string)$v]);
        }
    }
}
