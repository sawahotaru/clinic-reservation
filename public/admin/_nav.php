<?php
// 管理画面の共有ナビバー。
// 各ページで $navActive（キー）と $navTitle を定義してから include すること。
$navItems = [
    'index'    => ['予約一覧',   'index.php'],
    'closures' => ['休業・枠',    'closures.php'],
    'slots'    => ['予約枠設定',  'slots.php'],
    'mail'     => ['メール',      'mail.php'],
    'account'  => ['アカウント',  'account.php'],
];
$active = $navActive ?? '';
?>
<div class="admin-head">
  <h1><?= htmlspecialchars($navTitle ?? '管理') ?></h1>
  <nav class="admin-nav">
    <?php foreach ($navItems as $k => $it): ?>
      <a href="<?= $it[1] ?>"<?= $k === $active ? ' class="active"' : '' ?>><?= htmlspecialchars($it[0]) ?></a>
    <?php endforeach; ?>
    <a href="index.php?logout=1" class="logout">ログアウト</a>
  </nav>
</div>
