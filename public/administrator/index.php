<?php
// デコイ: Joomla 管理画面。実体は無く、アクセスを罠として記録する。
require __DIR__ . '/../../src/core/honeypot.php';
honeypot_trap('administrator');
