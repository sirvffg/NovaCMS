<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
session_start();

$userId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
if ($userId) {
    logoutCurrentDevice($userId);
}

session_destroy();
setcookie('device_token', '', time() - 3600, '/');
setcookie('nova_token', '', time() - 3600, '/');
header('Location: /admin/login.php');
exit;
