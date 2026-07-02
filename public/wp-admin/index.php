<?php
// デコイ: WordPress 管理ダッシュボード。実体は無く、アクセスを罠として記録する。
require __DIR__ . '/../../src/core/honeypot.php';
honeypot_trap('wp-admin');
