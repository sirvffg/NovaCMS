<?php
/**
 * Nova JSON API 核心逻辑
 *
 * 工作流程：
 *   1. 加载核心类（Request / Response / Server）
 *   2. 从 URL 提取路由路径
 *   3. 加载所有路由文件（通过 register_rest_route() 注册）
 *   4. Server::serve_request() → dispatch → JSON 输出
 */

// 确保 Session 已启动（根 index.php 可能已调用，但直接访问时仍需启动）
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// LY-008/LY-012: 安全响应头 + 隐藏服务器版本信息
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('X-XSS-Protection: 0');

// NOVA_API 常量：标识 API 入口，防止核心类文件被直接访问
if (!defined('NOVA_API')) {
    define('NOVA_API', true);
}

// =============================================
// 1. 加载环境
// =============================================
$baseDir = dirname(__DIR__, 2);
$novaDir = __DIR__;

require_once $baseDir . '/config/database.php';
require_once $baseDir . '/config/functions.php';
require_once $baseDir . '/config/privacy_functions.php';
require_once $baseDir . '/config/paid_functions.php';
require_once $baseDir . '/config/content_module_functions.php';

// 加载邮件配置（可能导致 HTML 输出并终止脚本，需要保护）
if (!defined('EMAIL_CONFIG_SILENT_FAILURE')) {
    define('EMAIL_CONFIG_SILENT_FAILURE', true);
}
ob_start();
$loadedEmailConfig = @require_once $baseDir . '/config/email_config.php';
ob_end_clean();

// 显式恢复用户登录状态（functions.php 底部自动执行可能受加载顺序影响）
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) {
    checkRememberMe();
}

// Bearer Token 认证（支持 Authoriztion: Bearer <token>）
if (empty($_SESSION['user_id'])) {
    nova_auth_bearer_token();
}

require_once $novaDir . '/class/rest/class-request.php';
require_once $novaDir . '/class/rest/class-response.php';
require_once $novaDir . '/class/rest/class-server.php';
require_once $novaDir . '/class/database/class-db.php';
require_once $novaDir . '/class/database/class-db-cache.php';
require_once $novaDir . '/class/database/class-db-schema.php';
require_once $novaDir . '/class/database/class-db-query.php';
require_once $novaDir . '/class/database/class-db-migration.php';
require_once $novaDir . '/class/database/class-db-seeder.php';
require_once $novaDir . '/class/system/class-hooks.php';
require_once $novaDir . '/class/system/class-api.php';
require_once $novaDir . '/class/plugin/class-plugin.php';
require_once $novaDir . '/class/theme/class-theme.php';
require_once $novaDir . '/class/filesystem/class-file.php';
require_once $novaDir . '/class/filesystem/class-upload.php';
require_once $novaDir . '/class/filesystem/class-image.php';
require_once $novaDir . '/class/backend/class-backend-menu.php';
require_once $novaDir . '/class/backend/class-backend-page.php';
require_once $novaDir . '/class/backend/class-backend-notice.php';
require_once $novaDir . '/class/backend/class-backend-ajax.php';
require_once $novaDir . '/class/backend/class-backend-list-table.php';

/**
 * 从 Authorization 头提取 Bearer Token 并设置用户登录状态
 * 用于非浏览器客户端（移动端 / 服务端 API 调用）
 * LY-006: 也支持从 HttpOnly Cookie 读取 token
 */
function nova_auth_bearer_token() {
    $token = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $token = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $token = $headers['Authorization'];
        }
    }

    // LY-006: 从 HttpOnly Cookie 读取 token（浏览器端自动携带）
    if (empty($token) && isset($_COOKIE['nova_token'])) {
        $token = 'Bearer ' . $_COOKIE['nova_token'];
    }

    if (!preg_match('/Bearer\s(\S+)/', $token, $matches)) {
        return;
    }

    $deviceToken = $matches[1];

    try {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT us.user_id, a.username, a.email, a.role, a.is_banned
             FROM user_sessions us
             JOIN admins a ON us.user_id = a.id
             WHERE us.device_token = ? AND us.is_active = 1
             AND us.status = 'success' AND us.expires_at > NOW()
             AND us.deleted_by_user = 0
             LIMIT 1"
        );
        $stmt->execute([$deviceToken]);
        $session = $stmt->fetch();

        if (!$session || !empty($session['is_banned'])) {
            return;
        }

        $_SESSION['user_id']       = (int)$session['user_id'];
        $_SESSION['user_username'] = $session['username'];
        $_SESSION['user_email']    = $session['email'] ?? '';
        $_SESSION['user_role']     = $session['role'];
    } catch (Exception $e) {
        // 静默失败，保持未登录状态
    }
}

// =============================================
// 4. 加载第三方插件和主题
// =============================================

$pluginsDir = dirname($novaDir) . '/nova-plugins';
if (is_dir($pluginsDir)) {
    foreach (glob($pluginsDir . '/*/plugin.php') as $plugin) {
        require_once $plugin;
    }
}

$themesDir = dirname($novaDir) . '/nova-themes';
if (is_dir($themesDir)) {
    foreach (glob($themesDir . '/*/theme.php') as $theme) {
        require_once $theme;
    }
}

// 触发 nova_init 钩子：让所有插件/主题执行 init() 注册路由
Nova_Hooks::do_action('nova_init');

/**
 * 获取当前请求的用户 ID
 * 全局通用，供所有路由文件使用
 */
function v1_get_current_user_id() {
    if (isset($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    if (isset($_SESSION['admin_id'])) return (int)$_SESSION['admin_id'];
    return 0;
}

/**
 * 检查用户是否为管理员
 * 全局通用，从数据库验证角色（不依赖 Session 缓存）
 */
function v1_is_admin($userId) {
    if ($userId <= 0) return false;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT role FROM admins WHERE id = ? AND is_banned = 0 LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row && $row['role'] === 'admin';
    } catch (Exception $e) {
        return false;
    }
}

// =============================================
// 2. 从 URL 提取路由路径
// =============================================
//
// 支持两种访问方式:
//   /nova-json/v1/posts              → 路径自动解析（推荐）
//   /nova-json/index.php?route=/v1/posts → 显式 route 参数
//
// Nginx try_files 将所有 /nova-json/* 转发到 /index.php，
// /index.php 拦截 /nova-json/* 路径后 require 本文件。

$route_path = '/';
if (isset($_GET['route'])) {
    $route_path = '/' . trim($_GET['route'], '/');
} else {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $pathOnly   = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    $pos = strpos($pathOnly, '/nova-json/');
    if ($pos !== false) {
        $route_path = '/' . trim(substr($pathOnly, $pos + strlen('/nova-json')), '/');
    }
}

// =============================================
// 3. 加载路由文件（插件）
// =============================================
//
// 路由文件使用 register_rest_route() 注册端点，
// 注册会排队到 rest_api_init 钩子，由 Server 在 serve_request 时自动执行。
//
// 插件开发者只需:
//   1. 在 routes/ 下创建 .php 文件
//   2. 在 $route_files 中添加文件名
//   3. 文件中用 register_rest_route() 注册端点

$route_files = [
    // 文章目录（posts / categories / tags / search）
    'posts/posts.php',        // 文章列表
    'posts/categories.php',   // 分类列表
    'posts/tags.php',         // 标签列表
    'posts/search.php',       // 搜索文章
    'posts/comments.php',     // 评论列表
    'posts/privacy.php',      // 隐私政策
    'posts/paid.php',         // 付费文章
    'posts/download.php',     // 下载文章

    //用户路由
    'users/users.php',        // 用户列表
    'users/auth.php',         // 用户认证

    // 友链路由
    'links/links.php',        // 友链列表
    'links/categories.php',   // 友链分类列表
    'links/apply.php',        // 友链申请
    'links/siteinfo.php',     // 友链站点信息

    // 相册路由（放在 statuses 目录下）
    'statuses/gallery-albums.php',  // 相册
    'statuses/gallery-photos.php',  // 照片

    // 站点路由
    'statuses/settings.php',  // 站点设置信息
    'statuses/shuoshuo.php',  // 说说
    'statuses/guestbook.php', // 留言板
    'statuses/terms.php',     // 协议与政策
    'public/proxy.php',       // 代理请求（公网 + 内部）
    
    // 内容功能路由
    'content/content.php',    // 页面、文档、订阅与智能问答

];

foreach ($route_files as $file) {
    $path = $novaDir . '/routes/' . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// =============================================
// 4. 创建 Server 并处理请求
// =============================================

$server   = new Nova_REST_Server();
$response = $server->serve_request($route_path);

// 输出响应
$status = $response->get_status();

// 非 2xx 状态码可能被 Nginx/Apache 拦截，用自定义头传递真实状态
if ($status >= 400) {
    header('X-Response-Status: ' . $status);
    http_response_code(200);
} else {
    http_response_code($status);
}

foreach ($response->get_headers() as $key => $val) {
    header("$key: $val");
}
echo $response->to_json();
