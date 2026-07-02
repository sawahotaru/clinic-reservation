<?php
// ===== 共有レイアウト =====
// 各ビューで重複していた <head>〜<main開始> と </main>〜</html> を1箇所に集約する。
//   page_head($title, $base) : ページ冒頭。$title はフルのタイトル文字列、
//                              $base はCSS等への相対プレフィックス（公開=''、管理='../'）。
//   page_foot()              : main と body/html を閉じる。
// ページ固有の <script>（インライン/src）は page_foot() の直前に出力すること。

if (!function_exists('page_head')) {
    function page_head(string $title, string $base = ''): void
    {
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>assets/style.css">
</head>
<body>
  <main class="container">
<?php
    }

    function page_foot(): void
    {
        ?>
  </main>
</body>
</html>
<?php
    }
}
