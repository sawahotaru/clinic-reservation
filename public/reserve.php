<?php
require __DIR__ . '/../src/core/db.php';
require __DIR__ . '/../src/core/functions.php';
require __DIR__ . '/../src/core/reservations.php';
require __DIR__ . '/../src/mail/notify.php';
require_once __DIR__ . '/../src/core/view.php';

$date  = $_REQUEST['date'] ?? '';
$time  = $_REQUEST['time'] ?? '';
$error = '';

$cfg = slotConfig($pdo);

// 連絡先の必須ルール（名前は常に必須）。'either'(どちらか) | 'phone' | 'email' | 'both'
$settings   = getSettings($pdo);
$contactReq = in_array($settings['contact_requirement'] ?? 'either', ['either', 'phone', 'email', 'both'], true)
    ? $settings['contact_requirement'] : 'either';
$phoneRequired = in_array($contactReq, ['phone', 'both'], true);
$emailRequired = in_array($contactReq, ['email', 'both'], true);

// 入力チェック（不正な日時で来たら入口へ戻す）
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    header('Location: index.php');
    exit;
}

// 営業ルール外・定休日・受付期間外の枠はURL直打ちでも弾く（入口へ戻す）。
// ※ 休憩帯の枠は generateSlots() が既に除外しているので in_array で弾かれる。
//    特定日の休業・手動ブロックは availableSlots() の残席判定に含まれる。
// ※ 曜日別モードに対応するため、その日付に適用される枠ルール（dayCfg）で判定する。
$dayCfg = dayCfg($cfg, $date);
if (isClosedDay($date, $cfg, dayOverride($pdo, $date))
    || !in_array($time, generateSlots($dayCfg), true)
    || !isBookable($date, $time, $dayCfg)
    || !in_array($time, availableSlots($pdo, $date, $cfg), true)) {
    header('Location: index.php?date=' . urlencode($date));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']  ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($name === '') {
        $error = 'お名前を入力してください。';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'メールアドレスの形式が正しくありません。';
    } elseif ($phoneRequired && $phone === '') {
        $error = '電話番号を入力してください。';
    } elseif ($emailRequired && $email === '') {
        $error = 'メールアドレスを入力してください。';
    } elseif ($contactReq === 'either' && $phone === '' && $email === '') {
        $error = '電話番号またはメールアドレスのどちらかを入力してください。';
    } else {
        try {
            // capacity を尊重した二重予約防止つきで作成（満席なら null）。
            $id = createReservation($pdo, [
                'date' => $date, 'time' => $time,
                'name' => $name, 'phone' => $phone, 'email' => $email,
            ], $cfg);

            if ($id === null) {
                $error = '申し訳ありません。その枠はすでに満席です。別の時間をお選びください。';
            } else {
                // 通知メール（お客さん控え＋お店通知）。送信に失敗しても予約は確定済み。
                sendReservationMails($pdo, [
                    'id' => $id, 'date' => $date, 'time' => $time,
                    'name' => $name, 'phone' => $phone, 'email' => $email,
                ]);

                // 完了画面へ（予約番号付き。メールを入れた場合は m=1。デモモードは demo=1）
                $url = 'complete.php?id=' . $id . '&date=' . urlencode($date) . '&time=' . urlencode($time);
                if ($email !== '') {
                    $url .= '&m=1';
                }
                if (isDemoMode($settings)) {
                    $url .= '&demo=1';
                }
                header('Location: ' . $url);
                exit;
            }
        } catch (PDOException $e) {
            $error = '申し訳ありません。エラーが発生しました。時間をおいて再度お試しください。';
        }
    }
}
?>
<?php page_head('予約内容の入力 | サンプル整体院'); ?>
    <h1>予約内容の入力</h1>

    <p class="selected">
      ご希望：<strong><?= htmlspecialchars($date) ?> <?= htmlspecialchars($time) ?></strong>
    </p>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" class="reserve-form">
      <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
      <input type="hidden" name="time" value="<?= htmlspecialchars($time) ?>">

      <label>お名前
        <input type="text" name="name" required
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </label>
      <?php if ($contactReq === 'either'): ?>
        <p class="hint">電話番号またはメールアドレスの<strong>どちらか一方は必ず</strong>ご入力ください。</p>
      <?php endif; ?>
      <label>電話番号<?= (!$phoneRequired && $contactReq !== 'either') ? '（任意）' : '' ?>
        <input type="tel" name="phone" <?= $phoneRequired ? 'required' : '' ?>
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </label>
      <label>メールアドレス<?= (!$emailRequired && $contactReq !== 'either') ? '（任意）' : '' ?>
        <input type="email" name="email" <?= $emailRequired ? 'required' : '' ?>
               placeholder="入力すると確認メールが届きます"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </label>

      <button type="submit">この内容で予約する</button>
    </form>

    <p><a href="index.php?date=<?= urlencode($date) ?>">← 別の時間を選ぶ</a></p>
<?php page_foot(); ?>
