<?php
require_once __DIR__ . '/../src/core/view.php';
$id     = $_GET['id']   ?? '';
$date   = $_GET['date'] ?? '';
$time   = $_GET['time'] ?? '';
$mailed = ($_GET['m'] ?? '') === '1';
$demo   = ($_GET['demo'] ?? '') === '1';
?>
<?php page_head('予約完了 | サンプル整体院'); ?>
    <h1>ご予約が完了しました</h1>

    <div class="ticket">
      <p class="reserve-no">予約番号 <strong>#<?= htmlspecialchars($id) ?></strong></p>
      <p class="selected">日時：<strong><?= htmlspecialchars($date) ?> <?= htmlspecialchars($time) ?></strong></p>
    </div>

    <?php if ($demo): ?>
      <p class="info">🧪 これはデモです。実際の確認メールは送信されません（本番では送信されます）。</p>
    <?php elseif ($mailed): ?>
      <p class="info">確認メールをお送りしました。届かない場合は迷惑メールフォルダもご確認ください。</p>
    <?php endif; ?>

    <p class="note">📷 この画面をスクリーンショット等で控えておいてください。</p>
    <p>当日はお気をつけてお越しください。ご変更・キャンセルはお電話でお願いいたします。</p>
    <p><a href="index.php">← トップへ戻る</a></p>
<?php page_foot(); ?>
