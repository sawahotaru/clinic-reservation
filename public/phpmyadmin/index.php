<?php
// デコイ: phpMyAdmin。DB 乗っ取りの常套パス。記録して弾く。
require __DIR__ . '/../../src/core/honeypot.php';
honeypot_trap('phpmyadmin');
