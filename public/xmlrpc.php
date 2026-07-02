<?php
// デコイ: WordPress XML-RPC。ブルートフォース/増幅の常套パス。記録して弾く。
require __DIR__ . '/../src/core/honeypot.php';
honeypot_trap('xmlrpc');
