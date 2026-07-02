<?php
require __DIR__ . '/../../src/core/db.php';
require __DIR__ . '/../../src/core/functions.php';     // slotConfig / generateSlots（枠の時刻候補）
require __DIR__ . '/../../src/core/closures.php';      // 休業・枠ふさぎのCRUD（モデル層）
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

// 特定日の終日指定（休業/営業）を登録（優先順位レイヤー1。同じ日付は上書き）
if (($_POST['action'] ?? '') === 'dayset') {
    setDayOverride($pdo, trim($_POST['day_date'] ?? ''), $_POST['day_status'] ?? '');
    header('Location: closures.php');
    exit;
}

// 特定日の指定を解除
if (($_POST['action'] ?? '') === 'dayunset' && !empty($_POST['date'])) {
    unsetDayOverride($pdo, $_POST['date']);
    header('Location: closures.php');
    exit;
}

// 個別の枠ふさぎを追加（開いている日の中のコマを埋める。複数選択可）
if (($_POST['action'] ?? '') === 'block') {
    addBlockedSlots($pdo, trim($_POST['block_date'] ?? ''), (array)($_POST['block_time'] ?? []));
    header('Location: closures.php');
    exit;
}

// 1枠ふさぎを解除
if (($_POST['action'] ?? '') === 'unblock' && !empty($_POST['id'])) {
    removeBlockedSlot($pdo, $_POST['id']);
    header('Location: closures.php');
    exit;
}

$overrides = allDayOverrides($pdo);
$blocks    = allBlockedSlots($pdo);
$slotCfg   = slotConfig($pdo);
$slotOpts  = generateSlots($slotCfg);   // 枠ふさぎの時刻候補

// カレンダーに付ける印（JSへ渡す）。特定日: open/closed、枠ふさぎ: その日にふさぎ有り
$overrideMarks = [];
foreach ($overrides as $o) { $overrideMarks[$o['date']] = $o['status']; }   // 'open' | 'closed'
$blockMarks = [];
foreach ($blocks as $b)   { $blockMarks[$b['date']] = 'block'; }

$navActive = 'closures';
$navTitle  = '休業・枠ふさぎ';
?>
<?php page_head('休業・枠ふさぎ | サンプル整体院', '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <h2>特定日の指定（休業・営業）</h2>
    <p class="hint">
      日付ごとに<strong>休業</strong>または<strong>営業（例外）</strong>を指定できます。
      この指定は<strong>定休日・祝日より優先</strong>されます（例: 定休日や祝日でもこの日だけ営業／平日でもこの日は休業）。
      毎週の定休日・休憩・祝日は <a href="slots.php">予約枠設定</a> で指定します。
    </p>

    <form method="post" class="reserve-form" id="dayset-form">
      <input type="hidden" name="action" value="dayset">
      <input type="hidden" name="day_date" id="dayset-date" required>
      <div class="cal" data-cal="dayset" data-marks='<?= htmlspecialchars(json_encode($overrideMarks), ENT_QUOTES) ?>'></div>
      <p class="cal-selected">選択中の日付：<strong id="dayset-label">カレンダーから日付を選んでください</strong></p>
      <fieldset class="field-group">
        <legend>指定</legend>
        <label class="radio"><input type="radio" name="day_status" value="closed" checked> 休業（この日は閉める）</label>
        <label class="radio"><input type="radio" name="day_status" value="open"> 営業（定休日・祝日でも開ける）</label>
      </fieldset>
      <button type="submit">この日を登録</button>
    </form>

    <?php if ($overrides): ?>
      <table class="admin-table">
        <thead><tr><th>日付</th><th>指定</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($overrides as $o): ?>
            <tr>
              <td><?= htmlspecialchars($o['date']) ?></td>
              <td><?= $o['status'] === 'open'
                    ? '<span class="badge-open">営業（例外）</span>'
                    : '<span class="badge-closed">休業</span>' ?></td>
              <td>
                <form method="post" onsubmit="return confirm('この指定を解除しますか？');">
                  <input type="hidden" name="action" value="dayunset">
                  <input type="hidden" name="date" value="<?= htmlspecialchars($o['date']) ?>">
                  <button type="submit" class="danger">解除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="empty">特定日の指定はありません。</p>
    <?php endif; ?>

    <hr class="sep">

    <h2>個別の枠ふさぎ</h2>
    <p class="hint">開いている日の中で、特定の<strong>枠だけ</strong>を埋めて予約不可にします（電話予約が入った枠など）。複数選択できます。</p>

    <form method="post" class="reserve-form" id="block-form">
      <input type="hidden" name="action" value="block">
      <input type="hidden" name="block_date" id="block-date" required>
      <div class="cal" data-cal="block" data-marks='<?= htmlspecialchars(json_encode($blockMarks), ENT_QUOTES) ?>'></div>
      <p class="cal-selected">選択中の日付：<strong id="block-label">カレンダーから日付を選んでください</strong></p>
      <fieldset class="field-group">
        <legend>ふさぐ時間（複数選択可）</legend>
        <label class="radio"><input type="checkbox" id="block-all"> <strong>すべて選択</strong></label>
        <div class="slot-checks">
          <?php foreach ($slotOpts as $t): ?>
            <label class="slotchk"><input type="checkbox" name="block_time[]" value="<?= htmlspecialchars($t) ?>"> <?= htmlspecialchars($t) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <button type="submit">選択した枠をふさぐ</button>
    </form>

    <?php if ($blocks): ?>
      <table class="admin-table">
        <thead><tr><th>日付</th><th>時間</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($blocks as $b): ?>
            <tr>
              <td><?= htmlspecialchars($b['date']) ?></td>
              <td><?= htmlspecialchars($b['time']) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('この枠ふさぎを解除しますか？');">
                  <input type="hidden" name="action" value="unblock">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="danger">解除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="empty">個別の枠ふさぎはありません。</p>
    <?php endif; ?>

  <!-- 常時表示カレンダー（管理・公開で共用） -->
  <script src="../assets/calendar.js"></script>
  <script>
  // 枠ふさぎ「すべて選択」
  (function () {
    var all = document.getElementById('block-all');
    if (!all) return;
    var boxes = document.querySelectorAll('input[name="block_time[]"]');
    all.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = all.checked; });
    });
    boxes.forEach(function (b) {
      b.addEventListener('change', function () {
        var every = true; boxes.forEach(function (x){ if (!x.checked) every = false; });
        all.checked = every;
      });
    });
  })();

  // 送信前チェック: 日付未選択を防ぐ
  (function () {
    var ds = document.getElementById('dayset-form');
    if (ds) ds.addEventListener('submit', function (e) {
      if (!document.getElementById('dayset-date').value) { e.preventDefault(); alert('カレンダーから日付を選んでください。'); }
    });
    var bf = document.getElementById('block-form');
    if (bf) bf.addEventListener('submit', function (e) {
      if (!document.getElementById('block-date').value) { e.preventDefault(); alert('カレンダーから日付を選んでください。'); return; }
      var any = false; document.querySelectorAll('input[name="block_time[]"]').forEach(function (x){ if (x.checked) any = true; });
      if (!any) { e.preventDefault(); alert('ふさぐ時間を1つ以上選んでください。'); }
    });
  })();
  </script>
<?php page_foot(); ?>
