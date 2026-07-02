<?php
require_once __DIR__ . '/../../src/core/db.php';   // $pdo, auth.php 読込済み
require_once __DIR__ . '/../../src/core/view.php';

$token   = $_GET['token'] ?? $_POST['token'] ?? '';
$purpose = (($_GET['p'] ?? $_POST['p'] ?? 'password') === 'login') ? 'login' : 'password';
$error   = '';
$done    = false;

// まずトークンの有効性（消費しない）
$valid = checkToken($pdo, $token, $purpose);

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($purpose === 'password' && ($_POST['action'] ?? '') === 'setpw') {
        $p1 = $_POST['password']  ?? '';
        $p2 = $_POST['password2'] ?? '';
        if (strlen($p1) < 8) {
            $error = 'パスワードは8文字以上にしてください。';
        } elseif ($p1 !== $p2) {
            $error = '確認用パスワードが一致しません。';
        } elseif (useToken($pdo, $token, $purpose)) {
            setPassword($pdo, $p1);
            $done = true;
        } else {
            $valid = false; // 競合等でトークンが無効化された
        }
    } elseif ($purpose === 'login' && ($_POST['action'] ?? '') === 'resetlogin') {
        if (useToken($pdo, $token, $purpose)) {
            resetLoginToInitial($pdo);
            $done = true;
        } else {
            $valid = false;
        }
    }
}
?>
<?php page_head(($purpose === 'login' ? 'ログインリセット' : 'パスワード再設定') . ' | 管理', '../'); ?>
    <?php if ($done && $purpose === 'password'): ?>
      <h1>パスワードを再設定しました</h1>
      <p class="info">新しいパスワードでログインできます。</p>
      <p><a href="index.php">→ ログイン画面へ</a></p>

    <?php elseif ($done && $purpose === 'login'): ?>
      <h1>ログイン情報をリセットしました</h1>
      <p class="info">ログインIDとパスワードを初期値に戻しました。サーバーに設定された初期IDとパスワードでログインしてください。</p>
      <p><a href="index.php">→ ログイン画面へ</a></p>

    <?php elseif (!$valid): ?>
      <h1>リンクが無効です</h1>
      <p class="error">このリンクは期限切れか、すでに使用済みです。お手数ですが、もう一度やり直してください。</p>
      <p><a href="reset_request.php?p=<?= htmlspecialchars($purpose) ?>">→ もう一度リンクを送る</a></p>

    <?php elseif ($purpose === 'login'): ?>
      <h1>ログイン情報のリセット</h1>
      <p>実行すると、<strong>ログインIDとパスワードがサーバー上の初期値に戻ります</strong>。よろしいですか？</p>
      <form method="post" class="reserve-form">
        <input type="hidden" name="action" value="resetlogin">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="p" value="login">
        <button type="submit" class="danger">初期値に戻す</button>
      </form>

    <?php else: ?>
      <h1>新しいパスワードの設定</h1>
      <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="post" class="reserve-form">
        <input type="hidden" name="action" value="setpw">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="p" value="password">
        <label>新しいパスワード（8文字以上）
          <input type="password" name="password" required autocomplete="new-password">
        </label>
        <label>新しいパスワード（確認）
          <input type="password" name="password2" required autocomplete="new-password">
        </label>
        <button type="submit">この内容で再設定</button>
      </form>
    <?php endif; ?>
<?php page_foot(); ?>
