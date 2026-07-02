<?php
// ===== 管理画面の共通ログインガード =====
// 管理ページの先頭で require する。ログインは「ID(メール)＋パスワード」。
// 未ログインならログイン画面（パスワード再設定・ログインリセットへの導線つき）を表示して停止。
// 事前に db.php が読み込まれ $pdo / auth.php が利用可能であること。

require_once __DIR__ . '/../core/view.php';

session_start();

// ログアウト
if (($_GET['logout'] ?? '') === '1') {
    session_destroy();
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $self);
    exit;
}

// ログイン処理
$loginError = '';
if (($_POST['action'] ?? '') === 'login') {
    $id   = $_POST['login_id'] ?? '';
    $pass = $_POST['password'] ?? '';
    if (verifyLogin($pdo, $id, $pass)) {
        $_SESSION['admin'] = true;
    } else {
        $loginError = 'ログインIDまたはパスワードが違います。';
    }
}

// 未ログインならログイン画面を表示して終了
if (empty($_SESSION['admin'])):
?>
<?php page_head('管理ログイン', '../'); ?>
    <h1>管理ログイン</h1>
    <?php if ($loginError): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    <form method="post" class="reserve-form">
      <input type="hidden" name="action" value="login">
      <label>ログインID（メールアドレス）
        <input type="email" name="login_id" required autocomplete="username"
               value="<?= htmlspecialchars($_POST['login_id'] ?? '') ?>">
      </label>
      <label>パスワード
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit">ログイン</button>
    </form>
    <p class="auth-links">
      <a href="reset_request.php?p=password">パスワードを忘れた方</a>
      ／
      <a href="reset_request.php?p=login">ログイン情報をリセット</a>
    </p>
<?php page_foot(); ?>
<?php
    exit;
endif;
