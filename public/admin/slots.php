<?php
require __DIR__ . '/../../src/core/db.php';            // settings / auth も読み込み済みになる
require __DIR__ . '/../../src/core/functions.php';     // generateSlots / slotConfig / weekdayCfg（枠プレビュー用）
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

$msg = '';
$msgType = 'info';
$weekLabels = ['日', '月', '火', '水', '木', '金', '土'];

// 予約枠の保存
if (($_POST['action'] ?? '') === 'slots') {
    $open  = trim($_POST['open_time']  ?? '');
    $close = trim($_POST['close_time'] ?? '');

    // 一律（フォールバック兼用）の営業時間は常に検証する
    if (!preg_match('/^\d{1,2}:\d{2}$/', $open) || !preg_match('/^\d{1,2}:\d{2}$/', $close)) {
        $msg = '営業時間は HH:MM 形式で入力してください。'; $msgType = 'error';
    } elseif (strtotime($open) >= strtotime($close)) {
        $msg = '営業終了は開始より後の時刻にしてください。'; $msgType = 'error';
    } else {
        // 定休日（曜日）: 0..6 のみ採用しカンマ連結（一律モードで使用）
        $weekdays = array_values(array_filter(
            (array)($_POST['closed_weekdays'] ?? []),
            fn($w) => is_numeric($w) && $w >= 0 && $w <= 6
        ));
        // 休憩時間（複数）: brk_start[] / brk_end[] のペア。両方入力された行のみ採用し、各々開始<終了を検証。
        $bStarts = (array)($_POST['brk_start'] ?? []);
        $bEnds   = (array)($_POST['brk_end']   ?? []);
        $breaks = [];
        $breakErr = false;
        foreach ($bStarts as $i => $bs) {
            $bs = trim((string)$bs);
            $be = trim((string)($bEnds[$i] ?? ''));
            if ($bs === '' && $be === '') {
                continue; // 空行は無視
            }
            if (!preg_match('/^\d{1,2}:\d{2}$/', $bs) || !preg_match('/^\d{1,2}:\d{2}$/', $be) || strtotime($bs) >= strtotime($be)) {
                $breakErr = true;
                break;
            }
            $breaks[] = $bs . '-' . $be;
        }

        // 一律 / 曜日別
        $mode = (($_POST['schedule_mode'] ?? 'uniform') === 'per_weekday') ? 'per_weekday' : 'uniform';

        // 曜日別テーブル（wd[w][...]）→ 空欄項目は持たせず、入力があるものだけJSONに残す。
        $wdCfg = [];
        $wdErr = false;
        $wdPost = (array)($_POST['wd'] ?? []);
        for ($w = 0; $w <= 6; $w++) {
            $e = (array)($wdPost[$w] ?? []);
            $entry = [];
            if (!empty($e['closed'])) {
                $entry['closed'] = true;
            }
            $wo = trim((string)($e['open']  ?? ''));
            $wc = trim((string)($e['close'] ?? ''));
            // 曜日別モードのときだけ、開始・終了が両方入っていれば形式と前後を検証
            if ($mode === 'per_weekday' && $wo !== '' && $wc !== ''
                && (!preg_match('/^\d{1,2}:\d{2}$/', $wo) || !preg_match('/^\d{1,2}:\d{2}$/', $wc) || strtotime($wo) >= strtotime($wc))) {
                $wdErr = true;
                break;
            }
            if ($wo !== '') $entry['open']  = $wo;
            if ($wc !== '') $entry['close'] = $wc;
            foreach (['slot_minutes' => 5, 'duration_minutes' => 5, 'capacity' => 1] as $k => $min) {
                $v = trim((string)($e[$k] ?? ''));
                if ($v !== '' && is_numeric($v)) {
                    $entry[$k] = (string)max($min, (int)$v);
                }
            }
            $wb = trim((string)($e['breaks'] ?? ''));
            if ($wb !== '') $entry['breaks'] = $wb;
            if ($entry) {
                $wdCfg[(string)$w] = $entry;
            }
        }

        // 連絡先の必須ルール
        $contactReq = in_array($_POST['contact_requirement'] ?? 'either', ['either', 'phone', 'email', 'both'], true)
            ? $_POST['contact_requirement'] : 'either';

        if ($breakErr) {
            $msg = '休憩時間は各行「開始＜終了」で入力してください（不要な行は空に）。'; $msgType = 'error';
        } elseif ($wdErr) {
            $msg = '曜日別の営業時間は各曜日「開始＜終了」で入力してください。'; $msgType = 'error';
        } else {
            saveSettings($pdo, [
                'open_time'           => $open,
                'close_time'          => $close,
                'slot_minutes'        => (string)max(5, (int)($_POST['slot_minutes']        ?? 30)),
                'duration_minutes'    => (string)max(5, (int)($_POST['duration_minutes']    ?? 30)),
                'capacity'            => (string)max(1, (int)($_POST['capacity']            ?? 1)),
                'lead_time_minutes'   => (string)max(0, (int)($_POST['lead_time_minutes']   ?? 0)),
                'booking_window_days' => (string)max(1, (int)($_POST['booking_window_days'] ?? 30)),
                'closed_weekdays'     => implode(',', $weekdays),
                'breaks'              => implode(',', $breaks),
                'close_on_holidays'   => (($_POST['close_on_holidays'] ?? '') === '1') ? '1' : '0',
                'schedule_mode'       => $mode,
                'weekday_config'      => json_encode($wdCfg, JSON_UNESCAPED_UNICODE),
                'contact_requirement' => $contactReq,
                // 旧キーは空に揃える（breaks へ統合済み）
                'break_start'         => '',
                'break_end'           => '',
            ]);
            $msg = '予約枠の設定を保存しました。';
        }
    }
}

$s = getSettings($pdo);

// 予約枠プレビュー（現在の設定で何枠出るか）
$slotCfg     = slotConfig($pdo);
$slotPreview = generateSlots($slotCfg);

// 保存がエラーになった時は「入力した値」を保持して再表示する
// （検証エラーで入力が消えると「値が反映しない（--のまま）」に見えるため）。
$slotsErr = ((($_POST['action'] ?? '') === 'slots') && $msgType === 'error');
$fv = fn(string $key, $fallback) => $slotsErr && isset($_POST[$key]) ? $_POST[$key] : $fallback;
// 定休日（チェック状態）
$closedSet = $slotsErr
    ? array_flip(array_map('strval', (array)($_POST['closed_weekdays'] ?? [])))
    : array_flip(array_filter(array_map('trim', explode(',', $s['closed_weekdays'])), fn($v) => $v !== ''));
// 休憩行（[['start','end'], ...]）
if ($slotsErr) {
    $breakRows = [];
    $ps = (array)($_POST['brk_start'] ?? []);
    $pe = (array)($_POST['brk_end']   ?? []);
    foreach ($ps as $i => $st) {
        $breakRows[] = ['start' => (string)$st, 'end' => (string)($pe[$i] ?? '')];
    }
    if (!$breakRows) { $breakRows = [['start' => '', 'end' => '']]; }
} else {
    $breakRows = $slotCfg['breaks'] ?: [['start' => '', 'end' => '']];
}
// 祝日トグル
$holChecked = $slotsErr ? (($_POST['close_on_holidays'] ?? '') === '1') : (($s['close_on_holidays'] ?? '0') === '1');

// 一律 / 曜日別モードと、曜日別テーブルのプレフィル
if ($slotsErr) {
    $scheduleMode  = (($_POST['schedule_mode'] ?? 'uniform') === 'per_weekday') ? 'per_weekday' : 'uniform';
    $wdData        = (array)($_POST['wd'] ?? []);
    $contactReqVal = $_POST['contact_requirement'] ?? 'either';
} else {
    $scheduleMode  = ($s['schedule_mode'] ?? 'uniform') === 'per_weekday' ? 'per_weekday' : 'uniform';
    $wdData        = json_decode((string)($s['weekday_config'] ?? ''), true);
    if (!is_array($wdData)) { $wdData = []; }
    $contactReqVal = $s['contact_requirement'] ?? 'either';
}
// 曜日別テーブルの値取得ヘルパ
$wv = function (int $w, string $key, string $default = '') use ($wdData) {
    $e = (array)($wdData[$w] ?? $wdData[(string)$w] ?? []);
    return isset($e[$key]) ? (string)$e[$key] : $default;
};
$wclosed = function (int $w) use ($wdData) {
    $e = (array)($wdData[$w] ?? $wdData[(string)$w] ?? []);
    return !empty($e['closed']);
};

$navActive = 'slots';
$navTitle  = '予約枠設定';
?>
<?php page_head('予約枠設定 | サンプル整体院', '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <?php if ($msg): ?>
      <p class="<?= $msgType === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>

    <p class="hint">空き枠はこのルールから<strong>自動計算</strong>されます（枠を1つずつ登録する必要はありません）。特定日の休業・営業は <a href="closures.php">休業・枠</a> で指定します。</p>
    <form method="post" class="reserve-form">
      <input type="hidden" name="action" value="slots">

      <fieldset class="field-group">
        <legend>営業時間の設定方法</legend>
        <label class="radio"><input type="radio" name="schedule_mode" value="uniform" <?= $scheduleMode !== 'per_weekday' ? 'checked' : '' ?>> 一律（全曜日とも同じ）</label>
        <label class="radio"><input type="radio" name="schedule_mode" value="per_weekday" <?= $scheduleMode === 'per_weekday' ? 'checked' : '' ?>> 曜日別（曜日ごとに変える）</label>
      </fieldset>

      <!-- 一律モードの設定（曜日別モードでは「空欄時のフォールバック値」として使われます） -->
      <div class="mode-pane" data-schedule="uniform">
        <div class="grid2">
          <label>営業開始
            <input type="time" name="open_time" value="<?= htmlspecialchars($fv('open_time', $s['open_time'])) ?>" required>
          </label>
          <label>営業終了
            <input type="time" name="close_time" value="<?= htmlspecialchars($fv('close_time', $s['close_time'])) ?>" required>
            <span class="hint">施術がこの時刻までに終わる枠だけ出します。</span>
          </label>
        </div>

        <div class="grid2">
          <label>枠の間隔（分）
            <input type="number" name="slot_minutes" min="5" step="5" value="<?= htmlspecialchars($fv('slot_minutes', $s['slot_minutes'])) ?>">
            <span class="hint">枠を出す刻み。例: 30 → 10:00, 10:30, 11:00…</span>
          </label>
          <label>施術時間（分）
            <input type="number" name="duration_minutes" min="5" step="5" value="<?= htmlspecialchars($fv('duration_minutes', $s['duration_minutes'])) ?>">
            <span class="hint">1回の所要時間。間隔より長くもできます（60分施術を30分刻みで出す等）。</span>
          </label>
        </div>

        <label>同時受付数（定員）
          <input type="number" name="capacity" min="1" step="1" value="<?= htmlspecialchars($fv('capacity', $s['capacity'])) ?>">
          <span class="hint">同じ時刻に受け付ける件数（ベッド数・スタッフ数）。1なら1枠1組。</span>
        </label>

        <fieldset class="field-group">
          <legend>定休日（毎週の休み）</legend>
          <div class="weekday-row">
            <?php foreach ($weekLabels as $i => $lbl): ?>
              <label class="weekday">
                <input type="checkbox" name="closed_weekdays[]" value="<?= $i ?>" <?= isset($closedSet[(string)$i]) ? 'checked' : '' ?>>
                <span><?= $lbl ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <span class="hint">チェックした曜日は終日休診（枠を出しません）。</span>
        </fieldset>

        <fieldset class="field-group">
          <legend>休憩時間（毎日。複数可）</legend>
          <div class="breaks" data-breaks>
            <?php foreach ($breakRows as $br): ?>
              <div class="break-row">
                <input type="time" name="brk_start[]" value="<?= htmlspecialchars($br['start']) ?>" aria-label="休憩開始">
                <span class="break-sep">〜</span>
                <input type="time" name="brk_end[]" value="<?= htmlspecialchars($br['end']) ?>" aria-label="休憩終了">
                <button type="button" class="link-danger" data-break-del>削除</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="secondary small" data-break-add>＋ 休憩を追加</button>
          <span class="hint">開始・終了の<strong>両方</strong>を入れてください（片方だけだと保存されません）。昼休み＋小休憩など複数行に分けられます。例: 12:00〜14:00 / 15:30〜15:45。</span>
        </fieldset>
      </div>

      <!-- 曜日別モードの設定 -->
      <div class="mode-pane" data-schedule="per_weekday">
        <p class="hint">曜日ごとに営業時間・枠・休憩を設定します。<strong>空欄の項目は上の「一律」の値を使います</strong>。「休診」にした曜日は終日休みです。休憩はカンマ区切りで複数可（例: <code>12:00-13:00,15:30-15:45</code>）。</p>
        <div class="wd-scroll">
          <table class="wd-table">
            <thead>
              <tr><th>曜日</th><th>休診</th><th>開始</th><th>終了</th><th>間隔</th><th>施術</th><th>定員</th><th>休憩(カンマ区切り)</th></tr>
            </thead>
            <tbody>
              <?php foreach ($weekLabels as $w => $lbl): ?>
                <tr<?= ($w === 0 ? ' class="wd-sun"' : ($w === 6 ? ' class="wd-sat"' : '')) ?>>
                  <th scope="row"><?= $lbl ?></th>
                  <td class="wd-center"><input type="checkbox" name="wd[<?= $w ?>][closed]" value="1" <?= $wclosed($w) ? 'checked' : '' ?>></td>
                  <td><input type="time" name="wd[<?= $w ?>][open]"  value="<?= htmlspecialchars($wv($w, 'open')) ?>" aria-label="<?= $lbl ?>営業開始"></td>
                  <td><input type="time" name="wd[<?= $w ?>][close]" value="<?= htmlspecialchars($wv($w, 'close')) ?>" aria-label="<?= $lbl ?>営業終了"></td>
                  <td><input type="number" min="5" step="5" name="wd[<?= $w ?>][slot_minutes]"     value="<?= htmlspecialchars($wv($w, 'slot_minutes')) ?>" aria-label="<?= $lbl ?>間隔"></td>
                  <td><input type="number" min="5" step="5" name="wd[<?= $w ?>][duration_minutes]" value="<?= htmlspecialchars($wv($w, 'duration_minutes')) ?>" aria-label="<?= $lbl ?>施術時間"></td>
                  <td><input type="number" min="1" step="1" name="wd[<?= $w ?>][capacity]"          value="<?= htmlspecialchars($wv($w, 'capacity')) ?>" aria-label="<?= $lbl ?>定員"></td>
                  <td><input type="text" name="wd[<?= $w ?>][breaks]" value="<?= htmlspecialchars($wv($w, 'breaks')) ?>" placeholder="12:00-13:00" aria-label="<?= $lbl ?>休憩"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 全モード共通 -->
      <div class="grid2">
        <label>最短リードタイム（分）
          <input type="number" name="lead_time_minutes" min="0" step="5" value="<?= htmlspecialchars($fv('lead_time_minutes', $s['lead_time_minutes'])) ?>">
          <span class="hint">直前予約の締切。例: 120 → 2時間前以降の枠は出さない。0なら制限なし。</span>
        </label>
        <label>予約可能な日数（先まで）
          <input type="number" name="booking_window_days" min="1" step="1" value="<?= htmlspecialchars($fv('booking_window_days', $s['booking_window_days'])) ?>">
          <span class="hint">今日から何日先まで予約を受けるか。</span>
        </label>
      </div>

      <fieldset class="field-group">
        <legend>祝日</legend>
        <label class="radio">
          <input type="checkbox" name="close_on_holidays" value="1" <?= $holChecked ? 'checked' : '' ?>>
          祝日を終日休診にする
        </label>
        <span class="hint">日本の祝日（振替休日含む）を自動で休診にします。祝日も営業する場合はOFF。</span>
      </fieldset>

      <fieldset class="field-group">
        <legend>予約フォーム（連絡先の必須）</legend>
        <label>必須にする連絡先
          <select name="contact_requirement">
            <option value="either" <?= $contactReqVal === 'either' ? 'selected' : '' ?>>電話かメールのどちらか（推奨）</option>
            <option value="phone"  <?= $contactReqVal === 'phone'  ? 'selected' : '' ?>>電話番号を必須</option>
            <option value="email"  <?= $contactReqVal === 'email'  ? 'selected' : '' ?>>メールアドレスを必須</option>
            <option value="both"   <?= $contactReqVal === 'both'   ? 'selected' : '' ?>>電話とメールの両方を必須</option>
          </select>
          <span class="hint">お名前は常に必須です。連絡先を必須にすると、いたずら予約を減らせます。</span>
        </label>
      </fieldset>

      <div class="info slot-preview">
        <?php if ($scheduleMode === 'per_weekday'): ?>
          現在の設定で出る枠（曜日別）：
          <ul class="wd-preview">
            <?php foreach ($weekLabels as $w => $lbl): ?>
              <?php $wc = weekdayCfg($slotCfg, $w); $wp = $wc['closed_today'] ? [] : generateSlots($wc); ?>
              <li>
                <strong><?= $lbl ?></strong>：
                <?php if ($wc['closed_today']): ?>
                  休診
                <?php elseif ($wp): ?>
                  <?= count($wp) ?>枠 — <?= htmlspecialchars(implode(' / ', $wp)) ?>
                <?php else: ?>
                  0枠（条件を満たす枠がありません）
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          現在の設定で出る枠（1日分）：
          <strong><?= count($slotPreview) ?>枠</strong>
          <?php if ($slotPreview): ?>
            — <?= htmlspecialchars(implode(' / ', $slotPreview)) ?>
          <?php else: ?>
            <strong>（条件を満たす枠がありません。営業時間や施術時間を見直してください）</strong>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <button type="submit">予約枠を保存</button>
    </form>

  <script>
  // 一律 / 曜日別 の切替で、該当ペインだけ表示する
  (function () {
    var radios = document.querySelectorAll('input[name="schedule_mode"]');
    var panes  = document.querySelectorAll('.mode-pane');
    if (!radios.length || !panes.length) return;
    function selected() {
      for (var i = 0; i < radios.length; i++) { if (radios[i].checked) return radios[i].value; }
      return 'uniform';
    }
    function update() {
      var cur = selected();
      panes.forEach(function (p) {
        var show = (p.getAttribute('data-schedule') === cur);
        p.hidden = !show;
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', update); });
    update();
  })();

  // 休憩時間: 行の追加・削除
  (function () {
    var wrap = document.querySelector('[data-breaks]');
    if (!wrap) return;
    function addRow() {
      var row = document.createElement('div');
      row.className = 'break-row';
      row.innerHTML =
        '<input type="time" name="brk_start[]" aria-label="休憩開始">' +
        '<span class="break-sep">〜</span>' +
        '<input type="time" name="brk_end[]" aria-label="休憩終了">' +
        '<button type="button" class="link-danger" data-break-del>削除</button>';
      wrap.appendChild(row);
    }
    document.querySelector('[data-break-add]').addEventListener('click', addRow);
    wrap.addEventListener('click', function (e) {
      if (!e.target.matches('[data-break-del]')) return;
      var rows = wrap.querySelectorAll('.break-row');
      if (rows.length > 1) {
        e.target.closest('.break-row').remove();
      } else {
        // 最後の1行は消さずに空にする（休憩なし＝空行は保存時に無視される）
        e.target.closest('.break-row').querySelectorAll('input').forEach(function (i) { i.value = ''; });
      }
    });
  })();
  </script>
<?php page_foot(); ?>
