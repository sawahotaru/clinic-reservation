<?php
require __DIR__ . '/../../src/core/db.php';
require __DIR__ . '/../../src/core/stats.php';
require __DIR__ . '/../../src/auth/admin_guard.php';   // 未ログインならここで停止

$today   = date('Y-m-d');
$hasData = statsHasData($pdo);

if ($hasData) {
    $summary = statsSummary($pdo, $today);
    $byMonth = statsByMonth($pdo, $today);
    $byWeek  = statsByWeekday($pdo);
    $byTime  = statsByTime($pdo);
    $repeat  = statsRepeat($pdo);
}

/** 棒グラフ1行。最大値を100%として幅を出す（0件の行も「0」と分かるように残す）。 */
function statBar(string $label, int $count, int $max, string $note = ''): void
{
    $pct = $max > 0 ? round($count / $max * 100) : 0;
    ?>
    <div class="stat-row">
      <span class="stat-label"><?= htmlspecialchars($label) ?></span>
      <span class="stat-bar"><span class="stat-fill" style="width: <?= $pct ?>%"></span></span>
      <span class="stat-value"><?= $count ?><?= $note !== '' ? '<small>' . htmlspecialchars($note) . '</small>' : '' ?></span>
    </div>
    <?php
}

/** 配列の最大 count。 */
function statMax(array $rows): int
{
    $max = 0;
    foreach ($rows as $r) {
        $max = max($max, (int)$r['count']);
    }
    return $max;
}

$navActive = 'stats';
$navTitle  = '統計';
?>
<?php page_head('統計 | サンプル整体院', '../'); ?>
    <?php require __DIR__ . '/_nav.php'; ?>

    <?php if (!$hasData): ?>
      <p class="empty">予約がまだ無いため、集計できるものがありません。</p>
    <?php else: ?>

      <div class="warn">
        ℹ️ ここに出るのは<strong>いま予約として残っているぶん</strong>です。
        キャンセルされた予約は記録ごと削除される仕組みのため、<strong>キャンセル率は分かりません</strong>。
      </div>

      <!-- サマリー: 最初に見たい4つ -->
      <section class="stat-card">
        <h2>いまの状況</h2>
        <div class="stat-tiles">
          <div class="stat-tile">
            <span class="stat-tile-label">今月（<?= htmlspecialchars($summary['this_month_label']) ?>）</span>
            <strong><?= $summary['this_month'] ?><small>件</small></strong>
            <?php
            $diff = $summary['this_month'] - $summary['last_month'];
            $sign = $diff > 0 ? '+' : '';
            ?>
            <span class="stat-tile-note">先月 <?= $summary['last_month'] ?>件（<?= $sign . $diff ?>）</span>
          </div>
          <div class="stat-tile">
            <span class="stat-tile-label">これからの予約</span>
            <strong><?= $summary['upcoming'] ?><small>件</small></strong>
            <span class="stat-tile-note">本日以降</span>
          </div>
          <div class="stat-tile">
            <span class="stat-tile-label">直近30日の来院</span>
            <strong><?= $summary['last30'] ?><small>件</small></strong>
            <span class="stat-tile-note">昨日まで</span>
          </div>
          <div class="stat-tile">
            <span class="stat-tile-label">記録されている予約</span>
            <strong><?= $summary['total'] ?><small>件</small></strong>
            <span class="stat-tile-note">過去ぶんを含む合計</span>
          </div>
        </div>
      </section>

      <!-- 月別 -->
      <section class="stat-card">
        <h2>月ごとの件数（直近12ヶ月）</h2>
        <p class="hint">予約が無かった月も 0 として並べています（谷が見えないと傾向が分からないため）。</p>
        <?php $max = statMax($byMonth); ?>
        <?php foreach ($byMonth as $row): ?>
          <?php statBar($row['label'], $row['count'], $max); ?>
        <?php endforeach; ?>
      </section>

      <!-- 曜日別 -->
      <section class="stat-card">
        <h2>曜日ごとの件数</h2>
        <p class="hint">定休日は 0 になります。<strong>定休日以外で極端に少ない曜日</strong>があれば、
           営業時間や枠の見直しの手がかりになります。</p>
        <?php $max = statMax($byWeek); ?>
        <?php foreach ($byWeek as $row): ?>
          <?php statBar($row['label'], $row['count'], $max); ?>
        <?php endforeach; ?>
      </section>

      <!-- 時間帯別 -->
      <section class="stat-card">
        <h2>時間帯ごとの件数</h2>
        <p class="hint">実際に予約が入った時刻だけを並べています。
           一部の時間帯に偏っているなら、<a href="slots.php">予約枠設定</a>の開始・終了時刻を寄せると受けやすくなります。</p>
        <?php $max = statMax($byTime); ?>
        <?php foreach ($byTime as $row): ?>
          <?php statBar($row['label'], $row['count'], $max); ?>
        <?php endforeach; ?>
      </section>

      <!-- リピート -->
      <section class="stat-card">
        <h2>リピートの状況</h2>
        <p class="hint">電話番号でまとめた<strong>おおよその目安</strong>です。
           同じ方が別の番号で予約された場合は別の方として数えられます。</p>
        <div class="stat-tiles">
          <div class="stat-tile">
            <span class="stat-tile-label">のべ人数</span>
            <strong><?= $repeat['unique'] ?><small>人</small></strong>
            <span class="stat-tile-note">電話番号の種類</span>
          </div>
          <div class="stat-tile">
            <span class="stat-tile-label">2回以上の方</span>
            <strong><?= $repeat['repeat'] ?><small>人</small></strong>
            <span class="stat-tile-note">
              <?= $repeat['unique'] > 0 ? round($repeat['repeat'] / $repeat['unique'] * 100) : 0 ?>%
            </span>
          </div>
          <div class="stat-tile">
            <span class="stat-tile-label">1回のみの方</span>
            <strong><?= $repeat['once'] ?><small>人</small></strong>
            <span class="stat-tile-note">再来のご案内先</span>
          </div>
        </div>

        <?php if ($repeat['top']): ?>
          <h3>ご来院の多い方</h3>
          <table class="admin-table">
            <thead><tr><th>お名前</th><th>回数</th><th>最後の予約日</th></tr></thead>
            <tbody>
              <?php foreach ($repeat['top'] as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['name']) ?></td>
                  <td><?= (int)$r['n'] ?>回</td>
                  <td><?= htmlspecialchars($r['last_date']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

    <?php endif; ?>
<?php page_foot(); ?>
