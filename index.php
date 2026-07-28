<?php
session_start();

// =============================================
// Nova JSON API 路由拦截
// =============================================
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/nova-json(/.*)?$#', $requestPath)) {
    require_once __DIR__ . '/vendor/nova-json/init.php';
    exit;
}

require_once 'config/database.php';
require_once 'config/functions.php';

// 记录访问
recordVisit('/');

// 处理退出登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (isset($_SESSION['user_id'])) {
        logoutCurrentDevice($_SESSION['user_id']);
    }
    session_destroy();
    setcookie('device_token', '', time() - 3600, '/');
    setcookie('nova_token', '', time() - 3600, '/');
    header('Location: /');
    exit;
}

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 获取激活主题
$activeTheme = !empty($config['active_theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $config['active_theme']) : 'default';
$themePath = __DIR__ . '/vendor/nova-themes/' . $activeTheme;
$themeUrl = '/vendor/nova-themes/' . $activeTheme;

// 定义主题URL常量，供主题文件使用
define('NOVA_THEME_URL', $themeUrl);

// 404 页面
function theme404() {
    global $themePath, $activeTheme;
    $file = $themePath . '/404.php';
    if (file_exists($file)) {
        require $file;
    } else {
        http_response_code(404);
        echo '<h1>404 - Page Not Found</h1>';
    }
    exit;
}

// 加载主题模板
function loadTheme($template) {
    global $themePath, $activeTheme;
    $file = $themePath . '/' . $template . '.php';
    if (file_exists($file)) {
        require $file;
    } else {
        theme404();
    }
    exit;
}

// =============================================
// 路由分发
// =============================================
$route = match(true) {
    $requestPath === '/' || $requestPath === '/index.php'                => 'index',
    $requestPath === '/blog' || $requestPath === '/blog.php'             => 'blog',
    strpos($requestPath, '/shuoshuo') === 0 || $requestPath === '/vendor/shuoshuo.php' => 'shuoshuo',
    strpos($requestPath, '/guestbook') === 0 || $requestPath === '/vendor/guestbook.php' => 'guestbook',
    strpos($requestPath, '/gallery') === 0 || $requestPath === '/vendor/gallery.php' => 'gallery',
    strpos($requestPath, '/friend-links') === 0 || $requestPath === '/vendor/friend-links.php' => 'friend-links',
    strpos($requestPath, '/announcement') === 0 || $requestPath === '/vendor/announcement.php' => 'announcement',
    $requestPath === '/profile' || $requestPath === '/vendor/profile.php' => 'profile',
    default => false,
};

if ($route === false) {
    // 不在路由表中的路径，尝试直接加载文件（兼容旧路径和登录/注册）
    $filePath = __DIR__ . $requestPath;
    if (file_exists($filePath) && is_file($filePath)) {
        require $filePath;
        exit;
    }
    theme404();
}

loadTheme($route);
