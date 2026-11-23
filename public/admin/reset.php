<?php
require_once __DIR__ . '/../../src/core/db.php';   // $pdo, auth.php 読込済み
require_once __DIR__ . '/../../src/core/view.php';
require_once __DIR__ . '/../../src/core/input.php';   // ?token[]=x のような配列注入を入口で潰す
require_once __DIR__ . '/../../src/auth/session.php'; // Cookie 属性 + CSRF

// この画面は未ログインで開く（メールのリンクから来る）ため admin_guard を通らない。
// 実行の鍵はメールで配った使い捨てトークンだが、CSRF トークンも併せて要求する。
// 「リンクを踏ませる」より「ログイン中の管理者に踏ませる」方が現実的な攻撃なので、
// 二重にしておく（トークンが漏れた場合の保険にもなる）。
session_boot();

// ⚠ 文字列化は必須。配列のまま checkToken() → hash() へ渡すと TypeError で Fatal になる。
$token   = get_str('token', post_str('token'));
$purpose = (get_str('p', post_str('p', 'password')) === 'login') ? 'login' : 'password';
$error   = '';
$done    = false;

// まずトークンの有効性（消費しない）
$valid = checkToken($pdo, $token, $purpose);

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'setpw') {
        $p1 = post_str('password');
        $p2 = post_str('password2');
        $error = newPasswordError($p1, $p2);
        if ($error === '') {
            if (useToken($pdo, $token, $purpose)) {
                setPassword($pdo, $p1);
                revokeAdminAccess($pdo);   // ほかの端末のログイン状態と未使用のリンクを無効にする
                $done = true;
            } else {
                $valid = false; // 競合等でトークンが無効化された
            }
        }
    }
}
?>
<?php page_head(($purpose === 'login' ? 'ログインリセット' : 'パスワード再設定') . ' | 管理', '../'); ?>
    <?php if ($done): ?>
      <h1>パスワードを再設定しました</h1>
      <p class="info">新しいパスワードでログインできます。2段階認証を有効にしている場合は、続けて認証コードを入力してください。</p>
      <p><a href="index.php">→ ログイン画面へ</a></p>

    <?php elseif (!$valid): ?>
      <h1>リンクが無効です</h1>
      <p class="error">このリンクは期限切れか、すでに使用済みです。お手数ですが、もう一度やり直してください。</p>
      <p><a href="reset_request.php?p=<?= htmlspecialchars($purpose) ?>">→ もう一度リンクを送る</a></p>

    <?php else: ?>
      <h1>新しいパスワードの設定</h1>
      <?php if ($purpose === 'login'): ?>
        <p>ログインIDは <strong><?= htmlspecialchars(adminId($pdo)) ?></strong> です。新しいパスワードを設定してください。</p>
      <?php endif; ?>
      <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="post" class="reserve-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="setpw">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="p" value="<?= htmlspecialchars($purpose) ?>">
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
