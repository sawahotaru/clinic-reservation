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

// ===== 2段階認証（TOTP）の有効化・無効化 =====
$twofaSecretShow = '';   // 有効化フロー中に表示する秘密鍵
$twofaUriShow    = '';
$recoveryShow    = [];   // 有効化直後に1度だけ表示するリカバリコード

if (($_POST['action'] ?? '') === 'twofa_start') {
    // 新しい秘密鍵を発行し、確認が済むまではセッションだけに保持（settingsには入れない）
    $_SESSION['twofa_setup_secret'] = totp_generate_secret();
}

if (($_POST['action'] ?? '') === 'twofa_confirm') {
    $secret = $_SESSION['twofa_setup_secret'] ?? '';
    $code   = $_POST['code'] ?? '';
    if ($secret === '') {
        $msg = '設定がタイムアウトしました。もう一度「有効化」からやり直してください。'; $msgType = 'error';
    } elseif (totp_verify($secret, $code)) {
        authSet($pdo, 'twofa_secret', $secret);
        authSet($pdo, 'twofa_enabled', '1');
        $recoveryShow = twofa_generate_recovery();
        authSet($pdo, 'twofa_recovery', json_encode(twofa_hash_recovery($recoveryShow)));
        unset($_SESSION['twofa_setup_secret']);
        $msg = '2段階認証を有効にしました。下の「リカバリコード」を必ず保管してください。';
    } else {
        $msg = '認証コードが違います。認証アプリに表示された6桁を入力してください。'; $msgType = 'error';
    }
}

if (($_POST['action'] ?? '') === 'twofa_disable') {
    authSet($pdo, 'twofa_enabled', '0');
    authSet($pdo, 'twofa_secret', '');
    authSet($pdo, 'twofa_recovery', '[]');
    unset($_SESSION['twofa_setup_secret']);
    $msg = '2段階認証を無効にしました。';
}

// 有効化フロー継続中なら、表示用の秘密鍵・otpauth URI を用意
if (!empty($_SESSION['twofa_setup_secret'])) {
    $twofaSecretShow = $_SESSION['twofa_setup_secret'];
    $twofaUriShow    = totp_uri($twofaSecretShow, adminId($pdo), 'クリニック予約');
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

    <hr class="sep">
    <h2>2段階認証（TOTP）</h2>

    <?php if ($recoveryShow): ?>
      <div class="warn">
        <strong>リカバリコード（この画面でしか表示されません）</strong><br>
        認証アプリを使えない時に、6桁コードの代わりに<strong>1回だけ</strong>使えます。印刷か安全な場所に保管してください。
        <ul class="recovery-codes">
          <?php foreach ($recoveryShow as $rc): ?><li><code><?= htmlspecialchars($rc) ?></code></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (twofaEnabled($pdo)): ?>
      <p class="info">状態: <strong>有効 ✓</strong>（ログイン時に認証アプリの6桁コードが必要です）</p>
      <form method="post" onsubmit="return confirm('2段階認証を無効にしますか？');">
        <input type="hidden" name="action" value="twofa_disable">
        <button type="submit" class="danger">2段階認証を無効にする</button>
      </form>

    <?php elseif ($twofaSecretShow): ?>
      <p class="hint">認証アプリ（Google Authenticator / Authy / Microsoft Authenticator など）に下の鍵を登録してください。</p>
      <ol class="totp-steps">
        <li>アプリで「アカウントを追加」→「セットアップキーを手動入力」を選ぶ（種類は「時間ベース/TOTP」）。</li>
        <li>キーに次の値を入力（アカウント名は任意）:
          <div class="totp-secret"><code><?= htmlspecialchars(trim(chunk_split($twofaSecretShow, 4, ' '))) ?></code></div>
        </li>
        <li>スマホでこのページを開いているなら、次のリンクのタップで直接登録できます:<br>
          <a href="<?= htmlspecialchars($twofaUriShow) ?>">認証アプリに登録する</a>
        </li>
        <li>アプリに表示された6桁を入力して有効化:</li>
      </ol>
      <form method="post" class="reserve-form">
        <input type="hidden" name="action" value="twofa_confirm">
        <label>認証コード（6桁）
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
        </label>
        <button type="submit">確認して有効化</button>
      </form>

    <?php else: ?>
      <p class="info">状態: <strong>無効</strong>。パスワードに加えて認証アプリの6桁コードを要求します（推奨）。</p>
      <form method="post">
        <input type="hidden" name="action" value="twofa_start">
        <button type="submit">2段階認証を有効化する</button>
      </form>
    <?php endif; ?>
<?php page_foot(); ?>
