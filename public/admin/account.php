<?php
require __DIR__ . '/../../src/core/db.php';
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

$msg = '';
$msgType = 'info';

// アカウント更新（ログインID・再設定先メール・パスワード）
if (($_POST['action'] ?? '') === 'account') {
    $newId = trim($_POST['login_id'] ?? '');
    $recov = trim($_POST['recovery_email'] ?? '');
    $np1   = $_POST['new_password']  ?? '';
    $np2   = $_POST['new_password2'] ?? '';
    if ($np1 !== '' && strlen($np1) < 8) {
        $msg = 'パスワードは8文字以上にしてください。'; $msgType = 'error';
    } elseif ($np1 !== '' && $np1 !== $np2) {
        $msg = '確認用パスワードが一致しません。'; $msgType = 'error';
    } else {
        if ($newId !== '') setAdminId($pdo, $newId);
        if ($recov !== '') setRecoveryEmail($pdo, $recov);
        if ($np1   !== '') setPassword($pdo, $np1);
        $msg = 'アカウント情報を更新しました。';
    }
}

$curId      = adminId($pdo);
$curRecover = recoveryEmail($pdo);

$navActive = 'account';
$navTitle  = '管理アカウント';
?>
<?php page_head('管理アカウント | サンプル整体院', '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <?php if ($msg): ?>
      <p class="<?= $msgType === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>

    <?php if (!isCustomAdmin($pdo)): ?>
      <div class="warn">
        ⚠️ 現在は<strong>初期設定のパスワード</strong>（ファイルに平文で記載）でログインしています。
        ここでログインID・パスワードを設定すると、暗号化して保存され安全になります。
      </div>
    <?php endif; ?>

    <form method="post" class="reserve-form">
      <input type="hidden" name="action" value="account">
      <label>ログインID（メールアドレス）
        <input type="email" name="login_id" value="<?= htmlspecialchars($curId) ?>">
      </label>
      <label>パスワード再設定の送信先メール
        <input type="email" name="recovery_email" value="<?= htmlspecialchars($curRecover) ?>">
        <span class="hint">パスワード忘れ・ログインリセットのリンクはここに届きます。</span>
      </label>
      <?php
        $pwFields = '<label>新しいパスワード（8文字以上）'
          . '<input type="password" name="new_password" autocomplete="new-password"></label>'
          . '<label>新しいパスワード（確認）'
          . '<input type="password" name="new_password2" autocomplete="new-password"></label>';
      ?>
      <?php if (isCustomAdmin($pdo)): ?>
        <details class="secret-edit">
          <summary>
            <span class="secret-mask">パスワード ••••••••</span>
            <span class="badge-ok">設定済み ✓</span>
            <span class="secret-toggle">変更</span>
          </summary>
          <div class="secret-body">
            <?= $pwFields ?>
            <span class="hint">変更しないなら閉じたままでOK。現在のパスワードは保持されます。</span>
          </div>
        </details>
      <?php else: ?>
        <div class="field-group">
          <?= $pwFields ?>
          <span class="hint">初期パスワードのままです。ここで設定すると暗号化して保存され安全になります（空欄なら変更しません）。</span>
        </div>
      <?php endif; ?>
      <button type="submit">アカウントを更新</button>
    </form>
<?php page_foot(); ?>
