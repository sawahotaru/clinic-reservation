<?php
require_once __DIR__ . '/../src/core/view.php';
require_once __DIR__ . '/../src/core/input.php';   // ?id[]=x のような配列注入を入口で潰す

// htmlspecialchars() に配列を渡すと TypeError で Fatal になるため、必ず文字列化する。
$id     = get_str('id');
$date   = get_str('date');
$time   = get_str('time');
$mailed = get_str('m')    === '1';
$demo   = get_str('demo') === '1';
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
