<?php
// ===== 管理画面の共通ログインガード =====
// 管理ページの先頭で require する。ログインは「ID(メール)＋パスワード」。
// 未ログインならログイン画面（パスワード再設定・ログインリセットへの導線つき）を表示して停止。
// 事前に db.php が読み込まれ $pdo / auth.php が利用可能であること。

require_once __DIR__ . '/../core/view.php';
require_once __DIR__ . '/../core/input.php';   // POST 値の配列注入を入口で潰す
require_once __DIR__ . '/session.php';         // Cookie 属性 / ID 打ち直し / CSRF
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/totp.php';

// ⚠ 素の session_start() は使わないこと。HttpOnly / Secure / SameSite が
//    既定（全部オフ）のまま開いてしまう。属性は session_boot() が付ける。
session_boot();

// ログアウト
if (($_GET['logout'] ?? '') === '1') {
    // session_destroy() だけでは $_SESSION も Cookie も残る。3点セットで消す。
    session_logout();
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $self);
    exit;
}

$loginError = '';

// ロック中メッセージ（試行回数制限）を組み立てる
$lockMsg = function (int $until): string {
    $mins = max(1, (int)ceil(($until - time()) / 60));
    return "試行回数が上限に達しました。約{$mins}分後に再度お試しください。";
};

// 1段階目: パスワード認証
if (($_POST['action'] ?? '') === 'login') {
    $tkey = throttleKey();
    if ($until = throttleLockedUntil($pdo, $tkey)) {
        $loginError = $lockMsg($until);
    } else {
        // 配列で POST されても verifyLogin() 内の trim()/hash_equals() が
        // TypeError にならないよう、ここで文字列に確定させる。
        $id   = post_str('login_id');
        $pass = post_str('password');
        if (!csrf_valid()) {
            // ログインフォームにもトークンを載せている。画面を開きっぱなしで
            // セッションが切れた場合もここに来るので、403 ではなく再入力を促す。
            $loginError = '送信が無効になりました。お手数ですが、もう一度入力してください。';
        } elseif (verifyLogin($pdo, $id, $pass)) {
            // ⚠ 権限が上がる瞬間にセッションIDを打ち直す（session fixation 対策）。
            //    2段階目待ちの状態も「未ログインより強い状態」なので、ここでも回す。
            session_rotate();
            if (twofaEnabled($pdo)) {
                $_SESSION['twofa_pending'] = 1;   // 2段階目待ち（まだ管理者にしない）
            } else {
                throttleReset($pdo, $tkey);
                $_SESSION['admin'] = true;
            }
        } else {
            throttleRegisterFail($pdo, $tkey);
            $loginError = 'ログインIDまたはパスワードが違います。';
        }
    }
}

// 2段階目: TOTP コード（またはリカバリコード）
if (($_POST['action'] ?? '') === 'twofa' && !empty($_SESSION['twofa_pending'])) {
    $tkey = throttleKey();
    if ($until = throttleLockedUntil($pdo, $tkey)) {
        $loginError = $lockMsg($until);
    } else {
        $code   = post_str('code');
        $secret = authGet($pdo, 'twofa_secret', '');
        if (!csrf_valid()) {
            $loginError = '送信が無効になりました。お手数ですが、もう一度入力してください。';
        } elseif (totp_verify($secret, $code) || twofa_consume_recovery($pdo, $code)) {
            throttleReset($pdo, $tkey);
            // ⚠ ここでも打ち直す。2FA を通って初めて管理者になるので、
            //    「パスワード認証だけ通った状態の ID」を管理者セッションとして
            //    使い回させない。権限が変わるたびに回す、が原則。
            session_rotate();
            unset($_SESSION['twofa_pending']);
            $_SESSION['admin'] = true;
        } else {
            throttleRegisterFail($pdo, $tkey);
            $loginError = '認証コードが違います。';
        }
    }
}

// 2段階目をやめて最初のログインに戻る
if (($_GET['cancel'] ?? '') === '1') {
    unset($_SESSION['twofa_pending']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 2段階目待ち: 認証コード入力画面を表示して終了
if (empty($_SESSION['admin']) && !empty($_SESSION['twofa_pending'])):
?>
<?php page_head('2段階認証', '../'); ?>
    <h1>2段階認証</h1>
    <?php if ($loginError): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    <p class="hint">認証アプリに表示される<strong>6桁のコード</strong>を入力してください。</p>
    <form method="post" class="reserve-form">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="twofa">
      <label>認証コード
        <input type="text" name="code" class="otp-input" inputmode="numeric"
               autocomplete="one-time-code" autofocus required>
      </label>
      <button type="submit">認証</button>
    </form>
    <p class="auth-links">
      認証アプリを使えない場合は<strong>リカバリコード</strong>も入力できます。
      ／ <a href="?cancel=1">最初からやり直す</a>
    </p>
<script src="../assets/otp-input.js"></script>
<?php page_foot(); ?>
<?php
    exit;
endif;

// ===== ここから先は「ログイン済みの管理者」だけが通る =====
// 管理画面の状態変更はすべて POST で、各ページは必ずこの admin_guard を
// 通ってから自前の POST 処理に入る。だから CSRF の検査はここ1箇所で足りる
// （ページごとに書くと、新しい画面を足したときに付け忘れる）。
//
// ⚠ Basic 認証は CSRF の防壁にならない。ブラウザが勝手に付けるので、
//    罠サイトからの POST にも認証情報が同伴する。トークンが要る理由はこれ。
if (!in_array($_POST['action'] ?? '', ['login', 'twofa'], true)) {
    csrf_require();
}

// 未ログインならログイン画面を表示して終了
if (empty($_SESSION['admin'])):
?>
<?php page_head('管理ログイン', '../'); ?>
    <h1>管理ログイン</h1>
    <?php if ($loginError): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    <form method="post" class="reserve-form">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="login">
      <label>ログインID（メールアドレス）
        <input type="email" name="login_id" required autocomplete="username"
               value="<?= htmlspecialchars(post_str('login_id')) ?>">
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
