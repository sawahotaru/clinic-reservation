<?php
require __DIR__ . '/../../src/core/db.php';
require __DIR__ . '/../../src/core/functions.php';     // slotConfig / generateSlots（今の設定を文中に出す）
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

// このページは読み取り専用。設定は変更せず、今の値を「あなたのお店では今こうなっています」と
// 見せるためだけに使う（ガイドの文章と実際の画面がズレないように）。
$s        = getSettings($pdo);
$slotCfg  = slotConfig($pdo);
$slots    = generateSlots($slotCfg);
$weekLbl  = ['日', '月', '火', '水', '木', '金', '土'];
$closedWd = array_filter(array_map('trim', explode(',', (string)($s['closed_weekdays'] ?? ''))), fn($v) => $v !== '');
$closedWdLabel = $closedWd ? implode('・', array_map(fn($w) => $weekLbl[(int)$w] . '曜', $closedWd)) : 'なし';

$navActive = 'guide';
$navTitle  = '使い方ガイド';
?>
<?php page_head('使い方ガイド | ' . ($s['shop_name'] ?: 'サンプル整体院'), '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <p class="guide-lead">
      このページは<strong>お店の方向けの説明書</strong>です。専門知識は要りません。
      上のメニューの並び順が、そのまま「よく使う順」になっています。
    </p>

    <nav class="guide-toc" aria-label="目次">
      <strong>目次</strong>
      <ol>
        <li><a href="#basics">まずは全体像（画面は2つあります）</a></li>
        <li><a href="#daily">毎日つかう：予約を見る・取り消す</a></li>
        <li><a href="#holiday">臨時のお休みを入れる</a></li>
        <li><a href="#block">電話で入った予約の枠をふさぐ</a></li>
        <li><a href="#slots">営業時間・受付ルールを変える</a></li>
        <li><a href="#mail">予約が入ったらメールで知らせる</a></li>
        <li><a href="#account">ログイン情報を変える・安全にする</a></li>
        <li><a href="#faq">困ったとき（よくある質問）</a></li>
      </ol>
    </nav>

    <!-- ============================================================ -->
    <h2 id="basics">1. まずは全体像（画面は2つあります）</h2>

    <div class="guide-two">
      <div class="guide-card">
        <h3>お客様が見る画面</h3>
        <p>ご予約ページです。カレンダーから日を選び、空いている時間を選んで、お名前と連絡先を入れると予約が完了します。</p>
        <p class="hint">お客様に案内するのはこちらのアドレスです（このページのアドレスではありません）。</p>
      </div>
      <div class="guide-card">
        <h3>お店の方が見る画面（今ここ）</h3>
        <p>予約の確認・お休みの設定・営業時間の変更など、お店側の操作をする画面です。パスワードで守られています。</p>
        <p class="hint">お客様からは見えません。アドレスも人には教えないでください。</p>
      </div>
    </div>

    <figure class="guide-shot">
      <img src="../assets/guide/public-top.png" alt="お客様が見るご予約ページ。カレンダーと空き時間が並んでいる">
      <figcaption>お客様が見るご予約ページ。ここで選ばれた予約が、次の「予約一覧」に入ります。</figcaption>
    </figure>

    <p><strong>お客様が予約すると、こうなります。</strong></p>
    <ol class="guide-steps">
      <li>その枠が自動で「予約済み」になり、他のお客様からは選べなくなります。</li>
      <li>「予約一覧」に1行増えます。</li>
      <li>設定してあれば、お店のメールアドレスに<strong>お知らせメール</strong>が届きます（→ <a href="#mail">6章</a>）。</li>
    </ol>

    <div class="guide-note">
      <strong>設置直後にやることは3つだけです。</strong>
      <ol>
        <li><a href="account.php">アカウント</a> でログインIDとパスワードをご自分のものに変える（→ <a href="#account">7章</a>）</li>
        <li><a href="slots.php">予約枠設定</a> で営業時間・定休日を実際のお店に合わせる（→ <a href="#slots">5章</a>）</li>
        <li><a href="mail.php">メール</a> で通知先のメールアドレスを入れる（→ <a href="#mail">6章</a>）</li>
      </ol>
    </div>

    <!-- ============================================================ -->
    <h2 id="daily">2. 毎日つかう：予約を見る・取り消す</h2>

    <p>メニューの <strong>「予約一覧」</strong> を開くと、入っている予約が新しい順に並びます。</p>

    <figure class="guide-shot">
      <img src="../assets/guide/admin-index.png" alt="予約一覧の画面。日付・時間・お名前・電話番号・メールが表の形で並んでいる">
      <figcaption>予約一覧。右端の「キャンセル」で1件ずつ取り消せます。</figcaption>
    </figure>

    <h3>予約を取り消すとき</h3>
    <ol class="guide-steps">
      <li>取り消したい行の右端にある <strong>「キャンセル」</strong> を押します。</li>
      <li>「この予約をキャンセルしますか？」と確認が出るので <strong>「OK」</strong> を押します。</li>
      <li>その枠は<strong>すぐに空きに戻り</strong>、他のお客様が予約できるようになります。</li>
    </ol>

    <div class="guide-warn">
      <strong>取り消しは元に戻せません。</strong>
      お客様への連絡（お電話など）は、この画面とは別に必要です。自動ではご連絡されません。
    </div>

    <!-- ============================================================ -->
    <h2 id="holiday">3. 臨時のお休みを入れる</h2>

    <p>「この日はお休みにしたい」というときは、メニューの <strong>「休業・枠」</strong> を使います。</p>

    <figure class="guide-shot">
      <img src="../assets/guide/admin-closures.png" alt="休業・枠ふさぎの画面。カレンダーと、休業か営業かを選ぶボタンが並んでいる">
      <figcaption>上半分が「その日ごと」の休業・営業の指定です。</figcaption>
    </figure>

    <ol class="guide-steps">
      <li>カレンダーで<strong>日付を押します</strong>（押した日が青くなります）。</li>
      <li>「休業（この日は閉める）」を選びます。</li>
      <li><strong>「この日を登録」</strong> を押します。</li>
    </ol>

    <p>登録した日は下の表に並びます。やめたくなったら <strong>「解除」</strong> を押せば元どおりです。</p>

    <div class="guide-note">
      <strong>逆に「定休日だけどこの日は開ける」もできます。</strong>
      同じ手順で「営業（定休日・祝日でも開ける）」を選んでください。
      <br>この日ごとの指定は、毎週の定休日や祝日の設定<strong>より優先</strong>されます。
    </div>

    <!-- ============================================================ -->
    <h2 id="block">4. 電話で入った予約の枠をふさぐ</h2>

    <p>
      お電話や店頭で予約を受けたときは、その時間をネット予約から外しておきます。
      同じ <strong>「休業・枠」</strong> のページの<strong>下半分</strong>です。
    </p>

    <ol class="guide-steps">
      <li>下側のカレンダーで<strong>日付を押します</strong>。</li>
      <li>ふさぎたい時間にチェックを入れます（<strong>いくつでも選べます</strong>）。</li>
      <li><strong>「選択した枠をふさぐ」</strong> を押します。</li>
    </ol>

    <p class="hint">
      1日まるごと押さえたいときは「すべて選択」が便利です。ただし丸一日お休みなら、
      <a href="#holiday">3章</a>の「休業」のほうが簡単です。
    </p>

    <!-- ============================================================ -->
    <h2 id="slots">5. 営業時間・受付ルールを変える</h2>

    <p>
      メニューの <strong>「予約枠設定」</strong> です。ここで決めたルールから、
      <strong>空き時間は自動で計算されます</strong>。1つずつ枠を登録する必要はありません。
    </p>

    <figure class="guide-shot">
      <img src="../assets/guide/admin-slots.png" alt="予約枠設定の画面。営業開始・終了、枠の間隔、施術時間、定休日などの入力欄">
      <figcaption>画面のいちばん下に「現在の設定で出る枠」が出ます。保存する前に確認できます。</figcaption>
    </figure>

    <div class="guide-now">
      <strong>いまのお店の設定</strong>
      <ul>
        <li>営業時間：<?= htmlspecialchars($s['open_time']) ?> 〜 <?= htmlspecialchars($s['close_time']) ?></li>
        <li>枠の間隔：<?= htmlspecialchars($s['slot_minutes']) ?>分ごと／施術時間：<?= htmlspecialchars($s['duration_minutes']) ?>分</li>
        <li>同時に受けられる件数：<?= htmlspecialchars($s['capacity']) ?>件</li>
        <li>定休日：<?= htmlspecialchars($closedWdLabel) ?></li>
        <li>1日に出る枠：<strong><?= count($slots) ?>枠</strong><?= $slots ? '（' . htmlspecialchars(implode(' / ', array_slice($slots, 0, 8))) . (count($slots) > 8 ? ' …' : '') . '）' : '' ?></li>
      </ul>
    </div>

    <h3>よく使う項目だけ、かんたんに</h3>
    <dl class="guide-dl">
      <dt>営業開始・営業終了</dt>
      <dd>受付する時間帯です。<strong>施術がこの時刻までに終わる枠だけ</strong>を出すので、終了間際の枠は自動で出なくなります。</dd>

      <dt>枠の間隔</dt>
      <dd>何分おきに枠を出すか。30 なら 10:00・10:30・11:00… と並びます。</dd>

      <dt>施術時間</dt>
      <dd>1回あたりの所要時間です。間隔より長くもできます（60分の施術を30分おきに出す、など）。</dd>

      <dt>同時受付数（定員）</dt>
      <dd>同じ時間に何組まで受けるか。ベッドが2台あるなら 2 です。</dd>

      <dt>定休日</dt>
      <dd>毎週のお休みです。チェックした曜日は終日、枠が出ません。</dd>

      <dt>休憩時間</dt>
      <dd>お昼休みなど。<strong>開始と終了の両方</strong>を入れてください。片方だけだと保存されません。複数の行に分けられます。</dd>

      <dt>最短リードタイム</dt>
      <dd>直前予約の締め切りです。120 なら「2時間後より先の枠だけ」出ます。0 なら制限なしです。</dd>

      <dt>予約可能な日数</dt>
      <dd>今日から何日先まで受け付けるか。30 なら1か月先まで出ます。</dd>
    </dl>

    <div class="guide-note">
      <strong>曜日ごとに営業時間が違うお店へ。</strong>
      いちばん上で「曜日別」を選ぶと、曜日ごとの表が出ます。
      <strong>空欄にした項目は「一律」の値がそのまま使われます</strong>ので、違うところだけ入れれば大丈夫です。
    </div>

    <div class="guide-warn">
      <strong>変更しても、すでに入っている予約は消えません。</strong>
      変わるのは<strong>これから出る空き枠</strong>だけです。
      設定を変えたあとに「入っているはずの予約」が枠と合わなくなることはありますが、予約一覧には残っています。
    </div>

    <!-- ============================================================ -->
    <h2 id="mail">6. 予約が入ったらメールで知らせる</h2>

    <p>メニューの <strong>「メール」</strong> です。</p>

    <figure class="guide-shot">
      <img src="../assets/guide/admin-mail.png" alt="メール設定の画面。店名・通知先メール・送信方法の選択・来院前のお知らせ">
      <figcaption>「通知先メール」に入れたアドレスへ、予約が入るたびにお知らせが届きます。下のほうに「来院前のお知らせ」もあります。</figcaption>
    </figure>

    <ol class="guide-steps">
      <li><strong>店名</strong> …… メールの文面やページに出るお店の名前です。</li>
      <li><strong>通知先メール</strong> …… 院長先生・お店のアドレス。<strong>ここに予約のお知らせが届きます。</strong></li>
      <li><strong>送信方法</strong> …… まずは「サーバー標準」のままで構いません。届かない・迷惑メールに入る場合は「Gmail」に変えます。</li>
      <li>最後に <strong>「設定を保存」</strong>。そのあと <strong>「現在の設定でテスト送信」</strong> を押すと、実際に1通届くか試せます。</li>
    </ol>

    <div class="guide-note">
      <strong>Gmail を選ぶ場合。</strong>
      Gmail の<strong>普段のパスワードは使えません</strong>。Google側で2段階認証をONにして「アプリパスワード」を発行し、
      表示された<strong>16桁</strong>を入力します。4つの欄に分かれていますが、
      <strong>まとめて貼り付ければ自動で分かれます</strong>。<br>
      この作業が難しければ、そのままご連絡ください。こちらで設定します。
    </div>

    <div class="guide-warn">
      <strong>「デモモード」がONの間は、メールは実際には送られません。</strong>
      展示やお試しのための安全装置です。本番で使い始めるときは<strong>必ずOFF</strong>にしてください。
    </div>

    <h3 id="reminder">来院前のお知らせ（リマインダー）</h3>

    <p>
      同じ「メール」の画面に <strong>「来院前のお知らせ」</strong> があります。ONにすると、
      <strong>ご予約日が近づいたお客様へ、お知らせメールが自動で1通</strong>届きます。
      「予約したのを忘れていた」という無断キャンセルを減らすためのものです。
    </p>

    <ol class="guide-steps">
      <li>チェックを入れて <strong>ON</strong> にします。</li>
      <li><strong>何日前／何時</strong> を選びます。たとえば「1日前・18時」なら、
          前日の夕方18時を過ぎたお客様から順に届きます。</li>
      <li><strong>「設定を保存」</strong> を押します。</li>
    </ol>

    <p>
      画面には <strong>いま送信を待っているご予約が何件あるか</strong> が出ます。
      すぐ試したいときは <strong>「お知らせメールを今すぐ送る」</strong> を押すと、時刻を待たずに送れます。
    </p>

    <div class="guide-note">
      <strong>知っておいていただきたいこと。</strong>
      <ul>
        <li>メールアドレスをご入力いただいた方だけが対象です（お電話だけのお客様には届きません）。</li>
        <li><strong>1件のご予約につき1通だけ</strong>です。同じ方へ何度も届くことはありません。</li>
        <li>お知らせの時刻より<strong>後</strong>に入ったご予約には送りません。
            ご予約直後の確認メールと重なってしまうためです。</li>
        <li>お客様がキャンセルされた予約には送りません（取り消した時点で対象から外れます）。</li>
      </ul>
    </div>

    <!-- ============================================================ -->
    <h2 id="account">7. ログイン情報を変える・安全にする</h2>

    <p>メニューの <strong>「アカウント」</strong> です。</p>

    <figure class="guide-shot">
      <img src="../assets/guide/admin-account.png" alt="管理アカウントの画面。ログインID・パスワードの変更と2段階認証の設定">
      <figcaption>ログインIDはメールアドレスの形で入れてください。</figcaption>
    </figure>

    <h3>パスワードを変える</h3>
    <ol class="guide-steps">
      <li>新しいログインID（メールアドレス）とパスワードを入れます。</li>
      <li><strong>「アカウントを更新」</strong> を押します。</li>
      <li>次からは新しいIDとパスワードでログインします。<strong>忘れないように控えてください。</strong></li>
    </ol>

    <h3>2段階認証（できればON）</h3>
    <p>
      パスワードに加えて、スマホのアプリに出る<strong>6桁の数字</strong>も入力する方式です。
      パスワードが誰かに知られても、それだけでは入れなくなります。
    </p>
    <ol class="guide-steps">
      <li>「2段階認証を有効化する」を押すと、四角い模様（QRコード）が出ます。</li>
      <li>スマホの認証アプリ（Google Authenticator など）で読み取ります。</li>
      <li>アプリに出た6桁を入力して「確認して有効化」。</li>
      <li>あわせて表示される<strong>リカバリコード</strong>を、紙に控えて保管してください。
          スマホを失くしたとき、これがないと入れなくなります。</li>
    </ol>

    <!-- ============================================================ -->
    <h2 id="faq">8. 困ったとき（よくある質問）</h2>

    <dl class="guide-dl">
      <dt>ログインできません</dt>
      <dd>
        ログイン画面の下にある <strong>「パスワードを忘れた方」</strong> から再設定できます。
        登録したメールアドレスに再設定用のリンクが届きます。
        何度か間違えると<strong>しばらく試せなくなります</strong>（不正なログインを防ぐためです）。数分待ってからお試しください。
      </dd>

      <dt>予約のお知らせメールが届きません</dt>
      <dd>
        「メール」の画面で ①<strong>デモモードがONになっていないか</strong> ②通知先メールが入っているか を確認し、
        <strong>「テスト送信」</strong> を試してください。迷惑メールフォルダもご確認ください。
        それでも届かないときは、送信方法を「Gmail」に変えると改善することが多いです。
      </dd>

      <dt>お客様の画面に空き時間が出ません</dt>
      <dd>
        「予約枠設定」の下にある <strong>「現在の設定で出る枠」</strong> を見てください。ここが0枠なら設定側の問題です。
        よくあるのは、営業時間が短すぎる・施術時間が長すぎる・休憩が営業時間を覆っている、の3つです。
        枠は出ているのに特定の日だけ出ない場合は、「休業・枠」でその日が休業になっていないかご確認ください。
      </dd>

      <dt>同じ時間に2件入ってしまいませんか</dt>
      <dd>
        入りません。同時に申し込みが重なっても、受け付けるのは定員までです。
        あふれたお客様には「ちょうど他の方のご予約が入りました」と表示され、別の時間を選んでいただけます。
      </dd>

      <dt>設定を変えたのに、お客様の画面が変わりません</dt>
      <dd>
        ブラウザが古い画面を覚えていることがあります。ページを<strong>再読み込み</strong>してみてください
        （スマホなら一度閉じて開き直す）。
      </dd>

      <dt>ここに書いていないことで困っています</dt>
      <dd>
        画面の操作で直せないこと（アドレスの変更、デザイン、動かなくなった等）は、
        こちらで対応します。<strong>ご自分で直そうとしなくて大丈夫です。</strong>
        「どの画面で」「何をしたら」「どうなったか」をお知らせいただけると早く解決できます。
      </dd>
    </dl>

    <p class="guide-foot">
      このガイドは管理画面からいつでも開けます（メニューの「使い方」）。印刷して手元に置いても構いません。
    </p>
<?php page_foot(); ?>
