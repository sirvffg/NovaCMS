<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/account_functions.php';
session_start();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '请从后台菜单安全退出。';
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '安全验证失败，请返回后台刷新后重试。';
    exit;
}

$adminOnlyUserId = !isset($_SESSION['user_id']) ? ($_SESSION['admin_id'] ?? null) : null;
if ($adminOnlyUserId) {
    logoutCurrentDevice($adminOnlyUserId);
}

logoutAuthenticatedUser();
header('Location: /admin/login.php');
exit;
