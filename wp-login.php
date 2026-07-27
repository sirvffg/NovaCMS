<?php
// ========== 目录蜜罐 (Directory Honeypot) ==========
// 这不是真正的 WordPress 登录页，而是蜜罐陷阱
// 真实用户绝不会访问此文件，只有扫描器/机器人会
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
ensureHoneypotTables();

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? __FILE__;

// 记录蜜罐触发
recordHoneypotTrigger($ip, $ua, 'directory_trap', $uri);

// 直接封禁（扫描 wp-login.php 的 100% 是机器人）
addBotBlacklist($ip, "Directory trap: $uri | UA: " . substr($ua, 0, 200));

// 返回一个假的登录页，让机器人以为是真的
http_response_code(200);
?><!DOCTYPE html>
<html>
<head><title>Login</title></head>
<body>
<h1>Login</h1>
<p>Please try again later.</p>
</body>
</html>
