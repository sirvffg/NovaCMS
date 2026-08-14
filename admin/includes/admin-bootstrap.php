<?php
/**
 * Secure bootstrap for newly refactored administration screens.
 * Session cookie options are installed before the session starts, then the
 * account is revalidated so revoked, banned or downgraded sessions stop
 * receiving administrator access immediately.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

// 框架启动标记（admin 页面直接访问，需自行定义）
if (!defined('NOVA_BOOTSTRAP')) {
    define('NOVA_BOOTSTRAP', true);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = getDB();
$adminSessionStatement = $db->prepare(
    "SELECT id, username
     FROM admins
     WHERE id = ? AND role = 'admin' AND COALESCE(is_banned, 0) = 0
     LIMIT 1"
);
$adminSessionStatement->execute([(int)$_SESSION['admin_id']]);
$authenticatedAdmin = $adminSessionStatement->fetch(PDO::FETCH_ASSOC);

if (!$authenticatedAdmin) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'] ?: '/',
            'domain' => $cookie['domain'] ?? '',
            'secure' => !empty($cookie['secure']),
            'httponly' => true,
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

$_SESSION['admin_id'] = (int)$authenticatedAdmin['id'];
$_SESSION['admin_username'] = (string)$authenticatedAdmin['username'];
$config = $db->query('SELECT * FROM website_config LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
