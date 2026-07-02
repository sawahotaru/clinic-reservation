<?php
require_once __DIR__ . '/../core/settings.php';

// ===== 予約通知メール =====
// 送信方法は管理画面の設定（settings）に従う:
//   - server : サーバー標準の mail()
//   - gmail  : Gmail の SMTP（PHPMailer を使用）
// 送信内容と結果は data/mail.log に記録する。送信失敗で予約処理は止めない。

/**
 * 予約確定時の通知。お客さん控え（メール入力時）とお店通知（notify_email設定時）。
 * @param array $r ['id','date','time','name','phone','email']
 */
function sendReservationMails(PDO $pdo, array $r): void
{
    $s    = getSettings($pdo);
    $shop = $s['shop_name'];
    $when = "{$r['date']} {$r['time']}";

    // お客さん宛：控え
    if (!empty($r['email'])) {
        $subject = "【{$shop}】ご予約を承りました";
        $body =
            "{$r['name']} 様\n\n" .
            "この度はご予約ありがとうございます。\n以下の内容で承りました。\n\n" .
            "──────────\n" .
            "予約番号 : {$r['id']}\n日時　　 : {$when}\n" .
            "お名前　 : {$r['name']} 様\nお電話　 : {$r['phone']}\n" .
            "──────────\n\n" .
            "ご来店をお待ちしております。\n" .
            "※このメールは送信専用です。ご変更・キャンセルはお電話でお願いいたします。\n\n{$shop}\n";
        sendMail($s, $r['email'], $subject, $body, null, 'customer');
    }

    // お店宛：お知らせ
    if (!empty($s['notify_email'])) {
        $subject = "【予約】{$when} {$r['name']} 様";
        $body =
            "新しい予約が入りました。\n\n" .
            "予約番号 : {$r['id']}\n日時　　 : {$when}\n" .
            "お名前　 : {$r['name']} 様\nお電話　 : {$r['phone']}\n" .
            "メール　 : " . ($r['email'] ?: '（未入力）') . "\n";
        sendMail($s, $s['notify_email'], $subject, $body, $r['email'] ?: null, 'shop');
    }
}

/** 設定のテスト送信（管理画面から呼ぶ）。[成功?, メッセージ] を返す */
function sendTestMail(PDO $pdo): array
{
    $s = getSettings($pdo);
    if (empty($s['notify_email'])) {
        return [false, '通知先メール（院長メール）が未設定です。先に設定して保存してください。'];
    }
    $body = "これは予約システムからのテスト送信です。\nこのメールが届けば設定は正常です。\n\n"
          . "送信方法: {$s['mail_method']}\n";
    [$ok, $err] = sendMail($s, $s['notify_email'], '【テスト送信】予約システム', $body, null, 'test');
    if (isDemoMode($s)) {
        return [true, "🧪 デモモードのため、実際にはメールを送信していません（送信内容は mail.log に記録）。実際の運用では送信されます。"];
    }
    return $ok
        ? [true, "テスト送信を実行しました（{$s['notify_email']} 宛）。受信箱と迷惑メールを確認してください。"]
        : [false, "送信に失敗しました: {$err}"];
}

/** デモモードか（設定で管理。trueなら実送信せず記録のみ） */
function isDemoMode(array $s): bool
{
    return ($s['demo_mode'] ?? '0') === '1';
}

/** 送信方法に応じて1通送る。[成功?, エラー文] を返す */
function sendMail(array $s, string $to, string $subject, string $body, ?string $replyTo, string $type): array
{
    $method = ($s['mail_method'] ?? 'server') === 'gmail' ? 'gmail' : 'server';

    // デモモード: 実際には送信せず、記録のみ（認証情報の悪用・送信枠消費を防ぐ）
    if (isDemoMode($s)) {
        $log = str_repeat('=', 50) . "\n"
            . '[' . date('Y-m-d H:i:s') . "] type={$type} method={$method} to={$to} sent=DEMO(未送信)\n"
            . "Subject: {$subject}\n\n" . $body . "\n";
        @file_put_contents(__DIR__ . '/../../data/mail.log', $log, FILE_APPEND);
        return [true, ''];
    }

    [$ok, $err] = $method === 'gmail'
        ? sendViaGmail($s, $to, $subject, $body, $replyTo)
        : sendViaServer($s, $to, $subject, $body, $replyTo);

    $log = str_repeat('=', 50) . "\n"
        . '[' . date('Y-m-d H:i:s') . "] type={$type} method={$method} to={$to} sent="
        . ($ok ? 'OK' : 'NG') . ($err ? " err={$err}" : '') . "\n"
        . "Subject: {$subject}\n\n" . $body . "\n";
    @file_put_contents(__DIR__ . '/../../data/mail.log', $log, FILE_APPEND);

    return [$ok, $err];
}

/** 日本語ヘッダ用のMIMEエンコード（mbstring不要） */
function mimeEncode(string $sub): string
{
    return '=?UTF-8?B?' . base64_encode($sub) . '?=';
}

/** サーバー標準の mail() で送信 */
function sendViaServer(array $s, string $to, string $subject, string $body, ?string $replyTo): array
{
    $from = mimeEncode($s['shop_name']) . ' <' . $s['mail_from'] . '>';
    $h  = "From: {$from}\r\nMIME-Version: 1.0\r\n";
    $h .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if ($replyTo) {
        $h .= "Reply-To: {$replyTo}\r\n";
    }
    try {
        $ok = @mail($to, mimeEncode($subject), $body, $h);
        return [$ok, $ok ? '' : 'mail()がfalseを返しました（サーバーにMTAが無い等）'];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/** Gmail の SMTP で送信（PHPMailer） */
function sendViaGmail(array $s, string $to, string $subject, string $body, ?string $replyTo): array
{
    require_once __DIR__ . '/lib/PHPMailer/Exception.php';
    require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

    if (empty($s['smtp_user']) || empty($s['smtp_pass'])) {
        return [false, 'Gmailアドレスまたはアプリパスワードが未設定です'];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $s['smtp_user'];
        $mail->Password   = $s['smtp_pass'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        // Gmailは送信元を認証アカウントに上書きするため From は Gmailアドレスにする
        $mail->setFrom($s['smtp_user'], $s['shop_name']);
        $mail->addAddress($to);
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return [true, ''];
    } catch (\Throwable $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}
