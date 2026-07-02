<?php
// .htaccess の rewrite から呼ばれる罠ディスパッチャ。
// 非PHPの機微ファイル探索（/.env, /.git/… 等）を label 付きで honeypot_trap に渡す。
require __DIR__ . '/../src/core/honeypot.php';

$allowed = ['env', 'git', 'file'];
$label = $_GET['l'] ?? 'file';
if (!in_array($label, $allowed, true)) {
    $label = 'file';
}
honeypot_trap($label);
