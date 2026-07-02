<?php
require __DIR__ . '/../src/core/db.php';
require __DIR__ . '/../src/core/functions.php';
require_once __DIR__ . '/../src/core/view.php';

// 日付を取得（未指定なら今日）。YYYY-MM-DD 以外は弾く。
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$cfg     = slotConfig($pdo);
$closed  = isClosedDay($date, $cfg, dayOverride($pdo, $date));
$slots   = $closed ? [] : availableSlotsDetailed($pdo, $date, $cfg);
$maxDate = date('Y-m-d', time() + $cfg['window'] * 86400); // 予約可能な最終日
?>
<?php page_head('ご予約 | サンプル整体院'); ?>
    <h1>ご予約</h1>
    <p class="hint">カレンダーから日付を選ぶと、その日の空き枠が表示されます。</p>

    <p class="cal-legend">
      <span><i class="lg-free"></i>空きあり</span>
      <span><i class="lg-full"></i>空きなし</span>
      <span><i class="lg-closed"></i>休診</span>
    </p>

    <div class="booking">
      <!-- 常時表示カレンダー（JSが calendar.js で描画。JS無効時は下のフォームを利用） -->
      <div class="cal cal-availability"
           data-cal-availability
           data-api="api/availability.php"
           data-selected="<?= htmlspecialchars($date) ?>"
           data-capacity="<?= (int)$cfg['capacity'] ?>"
           data-panel="slot-panel"></div>

      <!-- 選択日の空き枠（初期表示はサーバ描画、クリックで calendar.js が差し替え） -->
      <div id="slot-panel" class="slot-panel">
        <h2><?= htmlspecialchars($date) ?> の空き状況</h2>
        <?php if ($closed): ?>
          <p class="empty">この日は休診日です。別の日をお選びください。</p>
        <?php elseif (!$slots): ?>
          <p class="empty">この日に空いている枠はありません。別の日をお選びください。</p>
        <?php else: ?>
          <ul class="slots">
            <?php foreach ($slots as $slot): ?>
              <li>
                <a href="reserve.php?date=<?= urlencode($date) ?>&time=<?= urlencode($slot['time']) ?>">
                  <?= htmlspecialchars($slot['time']) ?>
                  <?php if ($cfg['capacity'] > 1): ?>
                    <span class="remaining">残<?= (int)$slot['remaining'] ?></span>
                  <?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <noscript>
      <form method="get" class="date-form" style="margin-top:16px">
        <label>ご希望日
          <input type="date" name="date" value="<?= htmlspecialchars($date) ?>"
                 min="<?= date('Y-m-d') ?>" max="<?= htmlspecialchars($maxDate) ?>">
        </label>
        <button type="submit">空き枠を見る</button>
      </form>
    </noscript>
  <script src="assets/calendar.js"></script>
<?php page_foot(); ?>
