<?php
session_start();

// 框架启动标记，用于防止直接访问敏感 PHP 文件
define('NOVA_BOOTSTRAP', true);

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
require_once 'config/content_module_functions.php';
require_once 'config/theme_functions.php';

// 记录访问
recordVisit($requestPath);

// =============================================
// 插件页面路由拦截
// 在主题路由之前，检查是否有插件注册了自定义页面路由
// =============================================
$pluginPageHandled = false;
$pluginPageDisabled = false;
$pluginPageName = '';
try {
    require_once __DIR__ . '/vendor/nova-json/class/plugin/class-plugin-registry.php';
    $allPlugins = Nova_Plugin_Registry::scan_all();

    // 读取启用列表
    $db0 = getDB();
    $activeIds = null;
    $cfgRow = $db0->query("SELECT active_plugins FROM website_config LIMIT 1")->fetch();
    if ($cfgRow && $cfgRow['active_plugins'] !== null) {
        $activeIds = json_decode($cfgRow['active_plugins'], true);
        if (!is_array($activeIds)) {
            $activeIds = [];
        }
    }

    foreach ($allPlugins as $pi) {
        if (!empty($pi['duplicate'])) {
            continue;
        }
        $pageRoutes = $pi['page_routes'] ?? [];
        if (empty($pageRoutes) || !is_array($pageRoutes)) {
            continue;
        }
        $isActive = $activeIds === null ? true : in_array($pi['id'], $activeIds, true);

        foreach ($pageRoutes as $pattern => $file) {
            // 将 {param} 转为正则捕获组
            $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^' . $regex . '$#u';

            if (preg_match($regex, $requestPath, $paramMatches)) {
                if (!$isActive) {
                    $pluginPageDisabled = true;
                    $pluginPageName = $pi['name'];
                    $pluginPageHandled = true;
                    break 2;
                }

                // 设置参数到 $_GET 供插件文件使用
                foreach ($paramMatches as $key => $val) {
                    if (is_string($key)) {
                        $_GET[$key] = $val;
                    }
                }

                // 定义常量让插件文件知道自己被页面路由调用
                if (!defined('NOVA_PAGE_ROUTE')) {
                    define('NOVA_PAGE_ROUTE', $pattern);
                }
                if (!defined('NOVA_PAGE_ROUTE_PLUGIN')) {
                    define('NOVA_PAGE_ROUTE_PLUGIN', $pi['id']);
                }

                $targetFile = $pi['plugin_dir'] . '/' . ltrim($file, '/');
                if (is_file($targetFile)) {
                    require_once $targetFile;
                }
                $pluginPageHandled = true;
                break 2;
            }
        }
    }
} catch (Throwable $e) {
    error_log('Plugin page route error: ' . $e->getMessage());
}

if ($pluginPageHandled) {
    if ($pluginPageDisabled) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $name = $pluginPageName !== '' ? "「{$pluginPageName}」" : '';
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>插件已禁用</title>'
           . '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8f9fa;">'
           . '<div style="text-align:center;padding:2rem;">'
           . '<div style="font-size:3rem;margin-bottom:1rem;">🔒</div>'
           . '<h1 style="font-size:1.5rem;color:#dc3545;margin:0 0 .5rem;">此插件已禁用</h1>'
           . '<p style="color:#6c757d;margin:0;">插件' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '已被禁用，无法访问此页面。</p>'
           . '</div></body></html>';
    }
    exit;
}

// 处理退出登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '请求验证失败，请刷新页面后重试。';
        exit;
    }

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

// 获取激活主题。管理员可通过带签名的地址临时预览其他已安装主题。
$configuredTheme = (string)($config['active_theme'] ?? 'default');
$previewTheme = is_string($_GET['nova_theme_preview'] ?? null) ? $_GET['nova_theme_preview'] : '';
$previewToken = is_string($_GET['nova_theme_token'] ?? null) ? $_GET['nova_theme_token'] : '';
$isThemePreview = !empty($_SESSION['admin_id'])
    && novaThemeValidatePreviewToken($previewTheme, $previewToken);

if ($isThemePreview) {
    $previewCandidate = novaThemeFind($previewTheme);
    if ($previewCandidate !== null && $previewCandidate['valid']) {
        $configuredTheme = $previewTheme;
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        define('NOVA_THEME_PREVIEW', true);
    }
}

$resolvedTheme = novaThemeResolveActive($configuredTheme);
if (!$resolvedTheme['valid']) {
    error_log('No valid NovaCMS theme is available: ' . implode('; ', $resolvedTheme['errors']));
    http_response_code(500);
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>主题不可用</title><body><h1>主题不可用</h1><p>请在后台检查主题文件。</p></body></html>';
    exit;
}
if (!empty($resolvedTheme['using_fallback'])) {
    error_log('NovaCMS theme fallback: ' . ($resolvedTheme['fallback_reason'] ?? 'unknown reason'));
}

$activeTheme = $resolvedTheme['slug'];
$themePath = $resolvedTheme['path'];
$themeUrl = '/vendor/nova-themes/' . rawurlencode($activeTheme);

// 定义主题URL常量，供主题文件使用
define('NOVA_THEME_URL', $themeUrl);

// 404 页面
function theme404() {
    global $themePath, $activeTheme, $themeUrl, $config, $db;
    http_response_code(404);
    $file = $themePath . '/404.php';
    if (file_exists($file)) {
        require $file;
    } else {
        echo '<h1>404 - Page Not Found</h1>';
    }
    exit;
}

// 加载主题模板
function loadTheme($template, array $data = []) {
    global $themePath, $activeTheme, $themeUrl, $config, $db;
    $file = $themePath . '/' . $template . '.php';
    if (file_exists($file)) {
        extract($data, EXTR_SKIP);
        require $file;
    } else {
        theme404();
    }
    exit;
}

// =============================================
// 路由分发
// =============================================
$pageMatches = [];
$documentMatches = [];
$documentDownloadMatches = [];
$route = match(true) {
    $requestPath === '/' || $requestPath === '/index.php'                => 'index',
    $requestPath === '/blog' || $requestPath === '/blog.php'             => 'blog',
    preg_match('#^/page/([^/]+)/?$#u', $requestPath, $pageMatches) === 1  => 'page',
    $requestPath === '/docs' || $requestPath === '/docs/'                 => 'docs',
    preg_match('#^/docs/([^/]+)/download/?$#u', $requestPath, $documentDownloadMatches) === 1 => 'document-download',
    preg_match('#^/docs/([^/]+)/?$#u', $requestPath, $documentMatches) === 1 => 'document',
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

$routeData = [];
try {
    if ($route === 'page') {
        $contentPage = contentModuleGetPublishedPageBySlug($pageMatches[1] ?? '');
        if (!$contentPage) {
            theme404();
        }
        $routeData['contentPage'] = $contentPage;
    } elseif ($route === 'docs') {
        $routeData['documentResults'] = contentModuleListPublishedDocuments([
            'page'     => $_GET['page'] ?? 1,
            'per_page' => 12,
            'category' => $_GET['category'] ?? '',
            'search'   => $_GET['q'] ?? '',
        ]);
        $routeData['documentCategories'] = contentModuleGetDocumentCategories();
    } elseif ($route === 'document') {
        $document = contentModuleGetPublishedDocumentBySlug($documentMatches[1] ?? '');
        if (!$document) {
            theme404();
        }
        $routeData['document'] = $document;
    } elseif ($route === 'document-download') {
        $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            exit;
        }
        $document = contentModuleGetPublishedDocumentBySlug($documentDownloadMatches[1] ?? '');
        $downloadUrl = contentModuleSafeUrl($document['file_url'] ?? '');
        if (!$document || $downloadUrl === '') {
            theme404();
        }

        // Count a document at most once per session in a short window so page
        // refreshes, browser retries and HEAD probes do not inflate statistics.
        if ($requestMethod === 'GET') {
            $downloadSessionKey = 'content_document_download_' . (int)$document['id'];
            $lastCountedAt = (int)($_SESSION[$downloadSessionKey] ?? 0);
            if ($lastCountedAt < time() - 600) {
                $statement = $db->prepare("UPDATE cms_documents SET download_count = download_count + 1 WHERE id = ? AND status = 'published'");
                $statement->execute([(int)$document['id']]);
                $_SESSION[$downloadSessionKey] = time();
            }
        }

        // External attachments pass through the existing departure page rather
        // than turning a trusted /docs URL into an immediate cross-site redirect.
        $downloadParts = parse_url($downloadUrl);
        if (is_array($downloadParts) && !empty($downloadParts['host'])) {
            $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $requestHost = preg_replace('/:\d+$/', '', $requestHost);
            $targetHost = strtolower((string)$downloadParts['host']);
            if ($requestHost === '' || !hash_equals(trim($requestHost, '[]'), trim($targetHost, '[]'))) {
                $downloadUrl = '/vendor/redirect.php?' . http_build_query([
                    'url'   => $downloadUrl,
                    'title' => (string)($document['title'] ?? '文档附件'),
                    'delay' => 3,
                ]);
            }
        }
        header('Location: ' . $downloadUrl, true, 302);
        exit;
    }
} catch (Throwable $e) {
    error_log('Content page rendering failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>服务暂不可用</title><body><h1>服务暂不可用</h1><p>页面加载失败，请稍后重试。</p></body></html>';
    exit;
}

loadTheme($route, $routeData);
