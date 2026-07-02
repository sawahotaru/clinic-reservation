<?php
require_once __DIR__ . '/../../src/core/db.php';   // $pdo, auth.php 読込済み
require_once __DIR__ . '/../../src/core/view.php';

$purpose = (($_GET['p'] ?? $_POST['p'] ?? 'password') === 'login') ? 'login' : 'password';
$title   = $purpose === 'login' ? 'ログイン情報のリセット' : 'パスワードの再設定';
$sent    = false;

if (($_POST['action'] ?? '') === 'request') {
    // トークン発行 → 初期メール宛にリンク送信（成否に関わらず同じ表示＝情報を漏らさない）
    $token = issueToken($pdo, $purpose);
    $url   = baseUrl() . '/reset.php?token=' . $token . '&p=' . $purpose;
    sendResetLink($pdo, $purpose, $url);
    $sent = true;
}
?>
<?php page_head($title . ' | 管理', '../'); ?>
    <h1><?= htmlspecialchars($title) ?></h1>

    <?php if ($sent): ?>
      <p class="info">
        登録されているメールアドレス宛に、<?= $purpose === 'login' ? 'ログインリセット' : 'パスワード再設定' ?>用のリンクを送信しました（1時間有効）。<br>
        メールが届かない場合は、迷惑メールフォルダや、サーバーのメール設定をご確認ください。
      </p>
      <p><a href="index.php">← ログイン画面へ戻る</a></p>
    <?php else: ?>
      <?php if ($purpose === 'login'): ?>
        <p>登録メールアドレスにリンクを送ります。リンクを開いて実行すると、
           <strong>ログインIDとパスワードがサーバー上の初期値に戻ります</strong>。</p>
      <?php else: ?>
        <p>登録メールアドレスに、パスワード再設定用のリンクを送ります。</p>
      <?php endif; ?>
      <form method="post" class="reserve-form">
        <input type="hidden" name="action" value="request">
        <input type="hidden" name="p" value="<?= htmlspecialchars($purpose) ?>">
        <button type="submit">登録メールにリンクを送る</button>
      </form>
      <p class="auth-links"><a href="index.php">← ログイン画面へ戻る</a></p>
    <?php endif; ?>
<?php page_foot(); ?>
