<?php
require __DIR__ . '/../../src/core/db.php';            // settings / auth も読み込み済みになる
require __DIR__ . '/../../src/mail/notify.php';
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

$msg = '';
$msgType = 'info';

// メール・お店の設定 保存
if (($_POST['action'] ?? '') === 'save') {
    $vals = [
        'shop_name'    => trim($_POST['shop_name'] ?? ''),
        'notify_email' => trim($_POST['notify_email'] ?? ''),
        'mail_method'  => (($_POST['mail_method'] ?? 'server') === 'gmail') ? 'gmail' : 'server',
        'mail_from'    => trim($_POST['mail_from'] ?? ''),
        'smtp_user'    => trim($_POST['smtp_user'] ?? ''),
        'demo_mode'    => (($_POST['demo_mode'] ?? '') === '1') ? '1' : '0',
    ];
    // アプリパスワードは4分割窓（smtp_pass[]）。結合し空白を除去。
    // 入力があった時だけ更新（空なら既存を保持）。1窓に16桁貼っても結合で復元される。
    $passParts = $_POST['smtp_pass'] ?? '';
    $newPass = is_array($passParts) ? implode('', $passParts) : (string)$passParts;
    $newPass = preg_replace('/\s+/', '', $newPass);
    if ($newPass !== '') {
        $vals['smtp_pass'] = $newPass;
    }
    saveSettings($pdo, $vals);
    $msg = '設定を保存しました。';
}

// テスト送信
if (($_POST['action'] ?? '') === 'test') {
    [$ok, $info] = sendTestMail($pdo);
    $msg = $info;
    $msgType = $ok ? 'info' : 'error';
}

$s = getSettings($pdo);
$hasPass = $s['smtp_pass'] !== '';

// アプリパスワードの4分割入力（使い回し用）
$passBoxesHtml = '<div class="apppass" data-apppass>'
    . str_repeat(
        '<input type="text" name="smtp_pass[]" maxlength="4" inputmode="text" autocomplete="off"'
        . ' autocapitalize="off" autocorrect="off" spellcheck="false" aria-label="アプリパスワード（4文字ずつ）"><span class="apppass-sep">-</span>',
        4
    );
$passBoxesHtml = preg_replace('#<span class="apppass-sep">-</span>$#', '', $passBoxesHtml) . '</div>';

$navActive = 'mail';
$navTitle  = 'メール設定';
?>
<?php page_head('メール設定 | サンプル整体院', '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <?php if ($msg): ?>
      <p class="<?= $msgType === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>

    <?php if (isDemoMode($s)): ?>
      <div class="warn">
        🧪 <strong>デモモードです。</strong>メールは<strong>実際には送信されず</strong>、内容の記録のみ行います。
        設定や操作は自由にお試しいただけます（本番運用では下の「デモモード」をOFFにしてください）。
      </div>
    <?php endif; ?>

    <form method="post" class="reserve-form">
      <input type="hidden" name="action" value="save">

      <label>店名
        <input type="text" name="shop_name" value="<?= htmlspecialchars($s['shop_name']) ?>">
      </label>

      <label>通知先メール（院長・お店）
        <input type="email" name="notify_email" placeholder="予約が入ったらここに通知が届きます"
               value="<?= htmlspecialchars($s['notify_email']) ?>">
      </label>

      <fieldset class="field-group">
        <legend>メール送信方法</legend>
        <label class="radio">
          <input type="radio" name="mail_method" value="server" <?= $s['mail_method'] !== 'gmail' ? 'checked' : '' ?>>
          サーバー標準（設定不要。ただし迷惑メールに入りやすい）
        </label>
        <label class="radio">
          <input type="radio" name="mail_method" value="gmail" <?= $s['mail_method'] === 'gmail' ? 'checked' : '' ?>>
          Gmail（届きやすい。アプリパスワードが必要）
        </label>
      </fieldset>

      <div class="method-pane" data-method="server">
        <label>送信元アドレス
          <input type="email" name="mail_from" value="<?= htmlspecialchars($s['mail_from']) ?>">
          <span class="hint">サーバー標準で送るときの送信元（From）アドレスです。</span>
        </label>
      </div>

      <fieldset class="field-group method-pane" data-method="gmail">
        <legend>Gmail設定</legend>
        <label>送信に使うGmailアカウント（メールアドレス）
          <input type="email" name="smtp_user" placeholder="例: clinic@gmail.com（送信元になります）"
                 value="<?= htmlspecialchars($s['smtp_user']) ?>">
          <span class="hint">このGmailアカウントから予約メールが送られます。下の「アプリパスワード」も<strong>このアカウントで発行</strong>したものを入れてください。</span>
        </label>
        <div class="apppass-field">
          <span class="apppass-label">アプリパスワード</span>
          <?php if ($hasPass): ?>
            <details class="secret-edit">
              <summary>
                <span class="secret-mask">●●●● ●●●● ●●●● ●●●●</span>
                <span class="badge-ok">設定済み ✓</span>
                <span class="secret-toggle">変更</span>
              </summary>
              <div class="secret-body">
                <?= $passBoxesHtml ?>
                <span class="hint">新しい16桁を入力して保存すると更新されます。閉じれば現在の設定のまま変わりません。</span>
              </div>
            </details>
          <?php else: ?>
            <?= $passBoxesHtml ?>
          <?php endif; ?>
        </div>
        <p class="hint">
          ※ Gmailの通常パスワードは使えません。Googleアカウントで<strong>2段階認証をON</strong>にし、
          「アプリパスワード」を発行してください。Googleが表示する<strong>16桁を4文字ずつ</strong>入力します。
          <strong>空白は不要</strong>。16桁をまとめて貼り付ければ自動で4つに分かれます。
        </p>
      </fieldset>

      <fieldset class="field-group">
        <legend>デモモード</legend>
        <label class="radio">
          <input type="checkbox" name="demo_mode" value="1" <?= ($s['demo_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
          デモモードにする（実際にはメールを<strong>送信せず</strong>、記録だけ行う）
        </label>
        <span class="hint">展示・お試し用の安全装置です。<strong>本番運用では必ずOFF</strong>にしてください（OFFで通常どおり送信されます）。</span>
      </fieldset>

      <button type="submit">設定を保存</button>
    </form>

    <form method="post" class="test-form">
      <input type="hidden" name="action" value="test">
      <button type="submit" class="secondary">現在の設定でテスト送信</button>
      <span class="hint">「通知先メール」宛に1通送って、届くか確認できます（保存後に実行）。</span>
    </form>

  <script>
  // メール送信方法（標準/Gmail）の切替で、該当する設定だけ表示する
  (function () {
    var radios = document.querySelectorAll('input[name="mail_method"]');
    var panes  = document.querySelectorAll('.method-pane');
    if (!radios.length || !panes.length) return;
    function selected() {
      for (var i = 0; i < radios.length; i++) { if (radios[i].checked) return radios[i].value; }
      return 'server';
    }
    function update() {
      var cur = selected();
      panes.forEach(function (p) {
        var show = (p.getAttribute('data-method') === cur);
        p.style.display = show ? '' : 'none';
        p.hidden = !show;
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', update); });
    update();
  })();

  // アプリパスワード4分割窓の操作補助（自動フォーカス送り・貼り付け自動分割・空白無視）
  (function () {
    var wrap = document.querySelector('[data-apppass]');
    if (!wrap) return;
    var boxes = Array.prototype.slice.call(wrap.querySelectorAll('input'));

    function distribute(text, startIndex) {
      var clean = text.replace(/\s+/g, '');
      var combined = '';
      for (var i = 0; i < startIndex; i++) combined += boxes[i].value;
      combined += clean;
      combined = combined.slice(0, 16);
      for (var j = 0; j < boxes.length; j++) {
        boxes[j].value = combined.slice(j * 4, j * 4 + 4);
      }
      var filled = Math.min(Math.floor(combined.length / 4), boxes.length - 1);
      boxes[filled].focus();
    }

    boxes.forEach(function (box, idx) {
      box.addEventListener('input', function () {
        if (/\s/.test(box.value)) { distribute(box.value, idx); return; }
        if (box.value.length >= 4 && idx < boxes.length - 1) {
          boxes[idx + 1].focus();
          boxes[idx + 1].select();
        }
      });
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && box.value === '' && idx > 0) {
          boxes[idx - 1].focus();
        }
      });
      box.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text');
        distribute(text, idx);
      });
    });

    var form = wrap.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        var combined = boxes.map(function (b) { return b.value; }).join('').replace(/\s+/g, '');
        if (combined.length > 0 && combined.length !== 16) {
          e.preventDefault();
          alert('アプリパスワードは16桁です（現在 ' + combined.length + '桁）。\n変更しない場合は4つの欄をすべて空にしてください。');
          boxes[0].focus();
        }
      });
    }
  })();
  </script>
<?php page_foot(); ?>
