<?php
require __DIR__ . '/../../src/core/db.php';
require __DIR__ . '/../../src/core/reservations.php';
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

// 予約キャンセル
if (($_POST['action'] ?? '') === 'cancel' && !empty($_POST['id'])) {
    deleteReservation($pdo, $_POST['id']);
    header('Location: index.php');
    exit;
}

$rows = allReservations($pdo);

$navActive = 'index';
$navTitle  = '予約一覧';
?>
<?php page_head('予約一覧 | サンプル整体院', '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <?php if (!isCustomAdmin($pdo)): ?>
      <div class="warn">
        ⚠️ 現在は<strong>初期設定のパスワード</strong>（ファイルに平文で記載）でログインしています。<br>
        安全のため、<a href="account.php">アカウント</a>でログインID・パスワードを設定してください。
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <p class="empty">予約はまだありません。</p>
    <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr><th>番号</th><th>日付</th><th>時間</th><th>お名前</th><th>電話番号</th><th>メール</th><th>受付日時</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['date']) ?></td>
              <td><?= htmlspecialchars($r['time']) ?></td>
              <td><?= htmlspecialchars($r['name']) ?></td>
              <td><?= htmlspecialchars($r['phone']) ?></td>
              <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('この予約をキャンセルしますか？');">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="danger">キャンセル</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
<?php page_foot(); ?>
