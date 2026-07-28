<?php
// 通用函数库

// -----------------------------------------
// Session Cookie 安全配置
// -----------------------------------------
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
}

// -----------------------------------------
// 静态资源管理 (CDN vs 离线)
// -----------------------------------------

/**
 * 获取静态资源URL (在线/离线自动切换)
 * @param string $localPath 本地资源相对路径 (如 /assets/css/bootstrap.min.css)
 * @param string $cdnPath 推荐的在线 CDN 链接
 * @return string 最终使用的链接
 */
function getResourceUrl($localPath, $cdnPath = '') {
    static $cdnConfig = null;
    if ($cdnConfig === null) {
        $configPath = __DIR__ . '/cdn_config.php';
        if (file_exists($configPath)) {
            $cdnConfig = include $configPath;
        } else {
            $cdnConfig = ['use_cdn' => false];
        }
    }
    
    // 如果开启了CDN并且提供了CDN链接，则使用CDN链接
    if (!empty($cdnConfig['use_cdn']) && !empty($cdnPath)) {
        return $cdnPath;
    }
    
    // 否则使用本地离线资源
    return $localPath;
}

/**
 * 获取当前资源加载模式（用于调试输出）
 * @return string '在线资源(CDN)' 或 '离线资源(Local)'
 */
function getResourceMode() {
    static $cdnConfig = null;
    if ($cdnConfig === null) {
        $configPath = __DIR__ . '/cdn_config.php';
        if (file_exists($configPath)) {
            $cdnConfig = include $configPath;
        } else {
            $cdnConfig = ['use_cdn' => false];
        }
    }
    return !empty($cdnConfig['use_cdn']) ? '在线资源 (CDN)' : '离线资源 (Local)';
}

// 检测是否为移动设备
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobileKeywords = [
        'mobile', 'android', 'iphone', 'ipod', 'ipad', 'blackberry', 
        'webos', 'incognito', 'webmate', 'bada', 'nokia', 'lg', 'ucweb',
        'skyfire', 'samsung', 'symbian', 'smartphone', 'windows phone',
        'phone', 'opera mini', 'opera mobi', 'fennec', 'minimo', 'tablet'
    ];
    
    foreach ($mobileKeywords as $keyword) {
        if (stripos($userAgent, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

// 记录访问统计（异步写入：DB 操作推迟到响应发送后执行，不阻塞页面渲染）
function recordVisit($page_url = '') {
    // 黑名单 IP 直接拦截（保持同步，需要立即 403）
    if (isBotBlacklisted()) {
        http_response_code(403);
        header('Location: /vendor/public/error/banned.html');
        exit;
    }

    // 先采集数据（轻量操作），其余工作推迟到 shutdown
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $page_url = $page_url ?: $_SERVER['REQUEST_URI'] ?? '';
    $visitor_username = $_SESSION['user_username'] ?? null;
    $visitor_email = $_SESSION['user_email'] ?? null;

    // 注册 shutdown 回调：在 HTML 响应已发送给浏览器之后才执行
    register_shutdown_function(function() use ($ip, $user_agent, $page_url, $visitor_username, $visitor_email) {
        // 确保蜜罐相关表存在
        ensureHoneypotTables();

        // 记录爬虫日志
        logCrawler();

        // 写入访问统计
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO visit_stats (ip_address, user_agent, page_url, visitor_username, visitor_email) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$ip, $user_agent, $page_url, $visitor_username, $visitor_email]);
    });
}

// 获取访问统计
function getVisitStats($days = 30) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            DATE(visit_time) as date,
            COUNT(*) as visits,
            COUNT(DISTINCT ip_address) as unique_visitors
        FROM visit_stats 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(visit_time)
        ORDER BY date DESC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// 获取页面访问统计
function getPageVisitStats($days = 30, $limit = 10) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            page_url,
            COUNT(*) as visits,
            COUNT(DISTINCT ip_address) as unique_visitors,
            MAX(visit_time) as last_visit
        FROM visit_stats 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY page_url
        ORDER BY visits DESC
        LIMIT ?
    ");
    $stmt->execute([$days, $limit]);
    return $stmt->fetchAll();
}

// 获取今日页面访问统计
function getTodayPageStats() {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            page_url,
            COUNT(*) as visits,
            COUNT(DISTINCT ip_address) as unique_visitors,
            HOUR(visit_time) as hour
        FROM visit_stats 
        WHERE DATE(visit_time) = CURDATE()
        GROUP BY page_url, HOUR(visit_time)
        ORDER BY visits DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// 获取访问趋势数据（用于图表）
function getVisitTrends($days = 7) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            DATE(visit_time) as date,
            COUNT(*) as total_visits,
            COUNT(DISTINCT ip_address) as unique_visitors,
            COUNT(CASE WHEN page_url = '/' THEN 1 END) as homepage_visits,
            COUNT(CASE WHEN page_url LIKE '/blog.php%' THEN 1 END) as blog_visits
        FROM visit_stats 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(visit_time)
        ORDER BY date ASC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// 获取热门页面统计
function getPopularPages($limit = 20) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            page_url,
            COUNT(*) as total_visits,
            COUNT(DISTINCT ip_address) as unique_visitors,
            AVG(CASE WHEN DATE(visit_time) = CURDATE() THEN 1 ELSE 0 END) * 100 as today_activity_rate
        FROM visit_stats 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY page_url
        HAVING total_visits >= 5
        ORDER BY total_visits DESC, unique_visitors DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// 获取总访问量
function getTotalVisits() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as total FROM visit_stats");
    return $stmt->fetch()['total'];
}

// 获取独立访客数
function getUniqueVisitors() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(DISTINCT ip_address) as total FROM visit_stats");
    return $stmt->fetch()['total'];
}

// Markdown转HTML（已弃用，现在使用客户端的 marked.js）
function parseMarkdown($text) {
    // 为了向后兼容，暂时保留此函数，但只返回转义后的文本
    // 实际的 Markdown 渲染现在在前端使用 marked.js 完成
    return htmlspecialchars($text);
}

// 安全输出
function e($string) {
    if ($string === null) {
        return '';
    }
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * 将字符串转换为 URL 友好的 slug
 * 用于页面路由、菜单 ID 等场景
 * @param string $title
 * @param string $separator
 * @return string
 */
function sanitize_title($title, $separator = '-') {
    // 移除 HTML 标签
    $title = strip_tags($title);
    // 将特殊字符替换为分隔符
    $title = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $title);
    // 将空格和连续分隔符替换为单个分隔符
    $title = preg_replace('/[\s\-_]+/', $separator, $title);
    // 去除首尾分隔符
    $title = trim($title, $separator);
    // 转小写
    $title = mb_strtolower($title, 'UTF-8');
    return $title ?: 'untitled';
}

/**
 * JavaScript 安全输出函数 - 防止XSS攻击
 * 适用于JavaScript变量、JSON等上下文
 *
 * @param mixed $value 要输出的值
 * @return string 安全的JSON编码字符串
 */
function js($value) {
    // 使用JSON编码，自动转义特殊字符
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : $json;
}

/**
 * JavaScript 属性安全输出函数 - 防止XSS攻击
 * 适用于HTML属性值（如 onclick="xxx"）
 *
 * @param string $string 要输出的字符串
 * @return string 安全的字符串
 */
function j($string) {
    if ($string === null) {
        return '';
    }
    // 转义HTML特殊字符和JavaScript特殊字符
    $string = str_replace('\\', '\\\\', $string);
    $string = str_replace('"', '\\"', $string);
    $string = str_replace("'", "\\'", $string);
    $string = str_replace("\r", '', $string);
    $string = str_replace("\n", '', $string);
    $string = str_replace("\t", ' ', $string);
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * URL 安全输出函数 - 防止XSS攻击
 * 适用于URL、链接等上下文
 *
 * @param string $url 要输出的URL
 * @return string 安全的URL
 */
function u($url) {
    if ($url === null) {
        return '';
    }
    // 验证URL格式
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    // 如果不是有效URL，返回空字符串
    return '';
}

// 检查登录状态
function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

// 需要登录
function requireLogin() {
    if (!isLoggedIn()) {
        // 如果是Ajax请求，返回JSON错误而不是重定向
        if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['ajax']) || isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '登录已过期，请重新登录', 'need_login' => true]);
            exit;
        }
        
        header('Location: /admin/login.php');
        exit;
    }
}

// License Key Encryption Helpers
define('LICENSE_ENCRYPTION_KEY', '1z2x3c4v5b6n7m');

function encryptLicenseKey($key) {
    // Pad the key to 16 bytes for AES-128
    $passphrase = str_pad(LICENSE_ENCRYPTION_KEY, 16, "\0");
    // Use AES-128-ECB for deterministic encryption (allows searching/exact matching)
    $encrypted = openssl_encrypt($key, 'AES-128-ECB', $passphrase, OPENSSL_RAW_DATA);
    return base64_encode($encrypted);
}

function decryptLicenseKey($encryptedKey) {
    $passphrase = str_pad(LICENSE_ENCRYPTION_KEY, 16, "\0");
    $decoded = base64_decode($encryptedKey);
    $decrypted = openssl_decrypt($decoded, 'AES-128-ECB', $passphrase, OPENSSL_RAW_DATA);
    if ($decrypted === false) {
        // Fallback for legacy keys (if they are not encrypted)
        // If decryption fails or produces garbage, we might return original
        // But for ECB, it usually "decrypts" to something.
        // We can check if it looks like "KEY-"
        return $encryptedKey; 
    }
    // Check if decrypted looks like a valid key (starts with KEY-)
    if (strpos($decrypted, 'KEY-') === 0) {
        return $decrypted;
    }
    // If it doesn't look like a key, maybe it wasn't encrypted?
    return $encryptedKey;
}

// 重定向
function redirect($url) {
    header("Location: $url");
    exit;
}

// 检查记住我状态并自动登录
function checkRememberMe() {
    $db = getDB();

    // 如果已经登录，检查是否被封禁、设备是否被下线
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $db->prepare("SELECT is_banned FROM admins WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user && !empty($user['is_banned'])) {
                // 如果被封禁，销毁会话并清除 cookie
                session_destroy();
                setcookie('device_token', '', time() - 3600, '/');
                setcookie('nova_token', '', time() - 3600, '/');
                // 重新启动空 session，避免当前请求仍显示登录状态
                session_start();
                return false;
            }

            // 检查当前设备是否被其他设备或管理员下线
            if (isset($_COOKIE['device_token'])) {
                $checkStmt = $db->prepare("SELECT is_active FROM user_sessions WHERE device_token = ? AND user_id = ? AND is_active = 1 LIMIT 1");
                $checkStmt->execute([$_COOKIE['device_token'], $_SESSION['user_id']]);
                if (!$checkStmt->fetch()) {
                    // 设备已被下线，销毁会话和 cookie
                    session_destroy();
                    setcookie('device_token', '', time() - 3600, '/');
                    setcookie('nova_token', '', time() - 3600, '/');
                    // 重新启动 session 以便后续可以设置新的 session 变量
                    session_start();
                    return false;
                }
            }

            // 登录状态下刷新设备活跃时间
            refreshDeviceActivity();
        } catch (Exception $e) {
            // 忽略错误
        }
        return true;
    }
    if (isset($_SESSION['admin_id'])) {
        return true;
    }

    // 尝试设备 Token 自动登录
    if (checkDeviceAutoLogin()) {
        return true;
    }
    return false;
}

// 尝试自动登录
if (session_status() === PHP_SESSION_ACTIVE) {
    checkRememberMe();
}

// 安全过滤重定向URL
function safeRedirectUrl($url) {
    // 1. 基础清理
    $url = trim($url ?? '');
    
    // 2. 如果为空，返回首页
    if (empty($url)) {
        return '/';
    }
    
    // 3. 禁止 javascript: 等危险协议 (防止 XSS)
    if (preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
        return '/';
    }
    
    // 4. 过滤非法字符 (防止 HTTP 头注入等)
    $url = str_replace(["\r", "\n", "%0d", "%0a"], '', $url);
    
    // 5. 验证是否为本站链接 (防止开放重定向)
    // 允许相对路径 (以 / 开头)
    if (strpos($url, '/') === 0) {
        // 排除 //example.com 这种相对协议的情况，防止绕过
        if (strpos($url, '//') === 0) {
             return '/';
        }
        return $url;
    }
    
    // 绝对 URL 仅允许 http(s)，且主机名必须与当前站点完全一致。
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '/';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '/';
    }

    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestHost = preg_replace('/:\d+$/', '', $requestHost);
    $requestHost = trim($requestHost, '[]');
    $urlHost = trim(strtolower((string)$parts['host']), '[]');
    if ($requestHost !== '' && hash_equals($requestHost, $urlHost)) {
        return $url;
    }
    
    // 默认返回首页
    return '/';
}

// 图片压缩函数
function compressImage($source, $destination, $quality = 90) {
    $info = getimagesize($source);
    if (!$info) return false;
    
    $mime = $info['mime'];
    
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = imagecreatefromjpeg($source);
            // 处理旋转 (EXIF Orientation)
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($source);
                if ($exif && isset($exif['Orientation'])) {
                    $orientation = $exif['Orientation'];
                    switch ($orientation) {
                        case 3:
                            $image = imagerotate($image, 180, 0);
                            break;
                        case 6:
                            $image = imagerotate($image, -90, 0);
                            break;
                        case 8:
                            $image = imagerotate($image, 90, 0);
                            break;
                    }
                }
            }
            // JPEG 质量 0-100
            imagejpeg($image, $destination, $quality);
            break;
            
        case 'image/png':
            $image = imagecreatefrompng($source);
            // 保留透明度
            imagealphablending($image, false);
            imagesavealpha($image, true);
            // PNG 压缩级别 0-9 (9为最高压缩，无损)
            // 这里我们固定使用高压缩比以减小体积
            imagepng($image, $destination, 9);
            break;
            
        case 'image/gif':
            $image = imagecreatefromgif($source);
            // GIF 不做处理，直接保存
            imagegif($image, $destination);
            break;
            
        default:
            // 不支持的格式，直接复制（如果源和目标不同）
            if ($source !== $destination) {
                copy($source, $destination);
            }
            return false;
    }
    
    if (isset($image) && is_resource($image) || $image instanceof GdImage) {
        imagedestroy($image);
    }
    return true;
}

// 记录爬虫访问
function logCrawler() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    // 常见爬虫列表
    $crawlers = [
        'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider',
        'YandexBot', 'Sogou', 'Exabot', 'facebot', 'ia_archiver', 'Bytespider',     
        'PetalBot', 'DotBot', 'AhrefsBot', 'SemrushBot', 'MJ12bot', 'AspiegelBot',
        'Sogou web spider', '360Spider', 'YisouSpider', 'HaosouSpider', 'DNSPod',
        'Yandex', 'Yahoo', 'ToutiaoSpider', 'Aliyun', 'Quark', 'The Knowledge AI'
    ];

    $isCrawler = false;
    $crawlerName = 'Unknown';

    foreach ($crawlers as $crawler) {
        if (stripos($userAgent, $crawler) !== false) {
            $isCrawler = true;
            $crawlerName = $crawler;
            break;
        }
    }

    if ($isCrawler) {
        // 使用 register_shutdown_function 在脚本执行结束时记录，以便获取 http_response_code
        register_shutdown_function(function() use ($crawlerName, $userAgent) {
            try {
                $db = getDB();
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $requestUrl = $_SERVER['REQUEST_URI'] ?? '';
                $statusCode = http_response_code();

                $stmt = $db->prepare("INSERT INTO crawler_logs (crawler_name, user_agent, ip_address, request_url, visit_time, status_code) VALUES (?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$crawlerName, $userAgent, $ip, $requestUrl, $statusCode]);
            } catch (Exception $e) {
                // 忽略错误
            }
        });
    }
}

// 安全路径验证函数 - 防止目录遍历攻击
function validatePath($path, $allowedBaseDir, $allowFiles = true) {
    // 1. 清理路径
    $path = trim($path);

    // 2. 空路径检查
    if (empty($path)) {
        return false;
    }

    // 3. 防止路径遍历攻击
    if (strpos($path, '..') !== false ||
        strpos($path, '\\0') !== false ||
        strpos($path, "\0") !== false) {
        return false;
    }

    // 4. 获取绝对路径
    $absolutePath = realpath($allowedBaseDir . DIRECTORY_SEPARATOR . $path);
    $realBaseDir = realpath($allowedBaseDir);

    // 5. 检查路径是否在允许的基础目录内
    if ($absolutePath === false || strpos($absolutePath, $realBaseDir) !== 0) {
        return false;
    }

    // 6. 检查文件/目录是否存在
    if (!file_exists($absolutePath)) {
        return false;
    }

    // 7. 如果不允许文件，检查是否为目录
    if (!$allowFiles && is_file($absolutePath)) {
        return false;
    }

    return $absolutePath;
}

// 安全URL验证函数 - 防止SSRF攻击
function validateUrl($url, $allowedSchemes = ['http', 'https']) {
    // 1. 基础清理
    $url = trim($url);

    // 2. 空URL检查
    if (empty($url)) {
        return false;
    }

    // 3. 禁止危险协议
    if (preg_match('/^\s*(javascript|data|vbscript|file|ftp):/i', $url)) {
        return false;
    }

    // 4. URL格式验证
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    // 5. 解析URL
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }

    // 6. 验证协议
    if (!in_array(strtolower($parsed['scheme']), $allowedSchemes, true)) {
        return false;
    }

    // 7. 禁止内网地址
    $host = strtolower($parsed['host']);

    // 7.0 白名单域名 - 直接放行，跳过所有内网/私有地址检查
    $allowedHosts = [
        'wallpaper.lygalaxy.cn',
    ];
    if (in_array($host, $allowedHosts, true)) {
        return $url;
    }

    // 7.1 禁止IP地址（可选，根据需求调整）
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        // 允许公网IP，禁止私有IP
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true; // 公网IP，允许
        } else {
            return false; // 私有IP或保留IP，禁止
        }
    }

    // 7.2 禁止特殊域名
    $bannedHosts = [
        'localhost',
        '127.0.0.1',
        '[::1]',
        '0.0.0.0',
        '169.254.169.254', // AWS/GCP元数据服务
        'metadata.google.internal',
        '169.254.169.250' // Azure元数据服务
    ];

    if (in_array($host, $bannedHosts, true)) {
        return false;
    }

    // 7.3 禁止内网域名
    $privatePatterns = [
        '/^10\./',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
        '/^192\.168\./',
        '/^127\./',
        '/^0\./',
        '/^169\.254\./'
    ];

    foreach ($privatePatterns as $pattern) {
        if (preg_match($pattern, $host)) {
            return false;
        }
    }

    return $url;
}

/**
 * 速率限制函数 - 防止暴力破解、垃圾评论等
 *
 * @param string $action 操作类型 (login, register, comment, email, etc.)
 * @param int $maxAttempts 最大尝试次数
 * @param int $timeWindow 时间窗口（秒）
 * @param string|null $identifier 自定义标识符（默认为IP地址）
 * @return array ['allowed' => bool, 'remaining' => int, 'retryAfter' => int]
 */
function checkRateLimit($action, $maxAttempts, $timeWindow, $identifier = null) {
    $db = getDB();
    
    // 使用IP地址作为默认标识符
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    try {
        // 创建速率限制表（如果不存在）
        $db->exec("CREATE TABLE IF NOT EXISTS `rate_limits` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `identifier` varchar(255) NOT NULL COMMENT '标识符(IP/用户ID)',
            `action` varchar(50) NOT NULL COMMENT '操作类型',
            `attempts` int(11) NOT NULL DEFAULT '1' COMMENT '尝试次数',
            `first_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次尝试时间',
            `last_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后尝试时间',
            `blocked_until` timestamp NULL DEFAULT NULL COMMENT '封锁到期时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_identifier_action` (`identifier`, `action`),
            KEY `idx_last_attempt` (`last_attempt`),
            KEY `idx_blocked_until` (`blocked_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='速率限制表'");
        
        // 清理过期记录（超过时间窗口且未被封禁的记录）
        $cleanupStmt = $db->prepare("DELETE FROM rate_limits WHERE blocked_until IS NULL AND last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $cleanupStmt->execute([$timeWindow]);
        
        // 查询当前记录
        $stmt = $db->prepare("SELECT * FROM rate_limits WHERE identifier = ? AND action = ? FOR UPDATE");
        $stmt->execute([$identifier, $action]);
        $record = $stmt->fetch();
        
        $currentTime = time();
        
        if ($record) {
            // 检查是否被封禁
            if ($record['blocked_until'] && strtotime($record['blocked_until']) > $currentTime) {
                $retryAfter = strtotime($record['blocked_until']) - $currentTime;
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'retryAfter' => $retryAfter,
                    'message' => '操作过于频繁，请在 ' . ceil($retryAfter / 60) . ' 分钟后再试'
                ];
            }
            
            // 检查是否在时间窗口内
            $firstAttemptTime = strtotime($record['first_attempt']);
            if ($currentTime - $firstAttemptTime < $timeWindow) {
                // 在时间窗口内，增加尝试次数
                if ($record['attempts'] >= $maxAttempts) {
                    // 超过限制，封禁一段时间（默认为时间窗口的2倍）
                    $blockDuration = $timeWindow * 2;
                    $blockedUntil = date('Y-m-d H:i:s', $currentTime + $blockDuration);
                    
                    $updateStmt = $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, blocked_until = ? WHERE id = ?");
                    $updateStmt->execute([$blockedUntil, $record['id']]);
                    
                    return [
                        'allowed' => false,
                        'remaining' => 0,
                        'retryAfter' => $blockDuration,
                        'message' => '操作过于频繁，请在 ' . ceil($blockDuration / 60) . ' 分钟后再试'
                    ];
                } else {
                    // 未超过限制，增加尝试次数
                    $updateStmt = $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = NOW() WHERE id = ?");
                    $updateStmt->execute([$record['id']]);
                    
                    return [
                        'allowed' => true,
                        'remaining' => $maxAttempts - $record['attempts'] - 1,
                        'retryAfter' => 0,
                        'message' => ''
                    ];
                }
            } else {
                // 超出时间窗口，重置计数
                $updateStmt = $db->prepare("UPDATE rate_limits SET attempts = 1, first_attempt = NOW(), last_attempt = NOW(), blocked_until = NULL WHERE id = ?");
                $updateStmt->execute([$record['id']]);
                
                return [
                    'allowed' => true,
                    'remaining' => $maxAttempts - 1,
                    'retryAfter' => 0,
                    'message' => ''
                ];
            }
        } else {
            // 创建新记录
            $insertStmt = $db->prepare("INSERT INTO rate_limits (identifier, action, attempts, first_attempt, last_attempt) VALUES (?, ?, 1, NOW(), NOW())");
            $insertStmt->execute([$identifier, $action]);
            
            return [
                'allowed' => true,
                'remaining' => $maxAttempts - 1,
                'retryAfter' => 0,
                'message' => ''
            ];
        }
    } catch (Exception $e) {
        // 如果速率限制系统出现错误，默认允许操作（不影响正常功能）
        error_log('Rate limit error: ' . $e->getMessage());
        return [
            'allowed' => true,
            'remaining' => $maxAttempts,
            'retryAfter' => 0,
            'message' => ''
        ];
    }
}

/**
 * 重置速率限制（用于成功的登录等）
 *
 * @param string $action 操作类型
 * @param string|null $identifier 自定义标识符
 */
function resetRateLimit($action, $identifier = null) {
    $db = getDB();
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    try {
        $stmt = $db->prepare("DELETE FROM rate_limits WHERE identifier = ? AND action = ?");
        $stmt->execute([$identifier, $action]);
    } catch (Exception $e) {
        error_log('Reset rate limit error: ' . $e->getMessage());
    }
}

/**
 * 生成CSRF Token
 *
 * @return string CSRF Token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证CSRF Token
 *
 * @param string|null $token 待验证的token
 * @return bool 是否验证通过
 */
function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 获取CSRF Token的HTML表单字段
 *
 * @return string HTML隐藏字段
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

// ========== 密码安全：统一哈希与验证（MD5 → bcrypt 无感迁移） ==========

/**
 * 对密码进行安全哈希（统一入口）
 * 所有新增/修改密码的地方都应使用此函数
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * 验证密码（统一入口，支持 MD5 和 bcrypt 双格式）
 *
 * 逻辑：
 *   1. 如果数据库存储的是 bcrypt 哈希（以 $2y$ 开头），直接用 password_verify
 *   2. 如果是旧的 MD5 哈希（32位十六进制），先用 md5 比对
 *   3. MD5 验证通过后，自动将数据库中的密码升级为 bcrypt（用户无感知）
 *
 * @param string $plainPassword 用户输入的明文密码
 * @param string $storedHash    数据库中存储的密码哈希
 * @param PDO    $db            数据库连接（用于自动升级时更新）
 * @param int    $userId        用户ID（用于自动升级时更新）
 * @return bool 密码是否正确
 */
function verifyPassword($plainPassword, $storedHash, $db = null, $userId = null) {
    if (empty($storedHash)) {
        return false;
    }

    // bcrypt 格式（以 $2y$ 开头）
    if (strpos($storedHash, '$2y$') === 0) {
        return password_verify($plainPassword, $storedHash);
    }

    // 旧 MD5 格式：32位十六进制
    if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
        if (md5($plainPassword) === $storedHash) {
            // MD5 验证通过，自动升级为 bcrypt
            if ($db !== null && $userId !== null) {
                try {
                    $newHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    $stmt->execute([$newHash, $userId]);
                } catch (Exception $e) {
                    error_log('Password upgrade to bcrypt failed for user ' . $userId . ': ' . $e->getMessage());
                }
            }
            return true;
        }
        return false;
    }

    // 未知格式，尝试 password_verify 兜底
    return password_verify($plainPassword, $storedHash);
}

// ========== 设备管理与登录审计系统 ==========

/**
 * 自动创建 user_sessions 表
 */
function ensureSessionTables() {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT '关联 admins.id',
            device_token VARCHAR(128) NOT NULL COMMENT '设备唯一标识（Cookie 值）',
            device_name VARCHAR(100) DEFAULT '' COMMENT '设备名称（自动解析）',
            ip_address VARCHAR(45) NOT NULL COMMENT '登录 IP',
            user_agent VARCHAR(500) DEFAULT '' COMMENT '浏览器 UA',
            login_method VARCHAR(20) DEFAULT 'password' COMMENT '登录方式: password/auto',
            is_active TINYINT(1) DEFAULT 1 COMMENT '是否活跃: 1=在线, 0=已下线/过期',
            is_current TINYINT(1) DEFAULT 0 COMMENT '是否当前设备: 1=是',
            status VARCHAR(20) DEFAULT 'success' COMMENT '结果: success/failed',
            fail_reason VARCHAR(200) DEFAULT NULL COMMENT '失败原因',
            login_at DATETIME NOT NULL COMMENT '登录时间',
            last_active_at DATETIME DEFAULT NULL COMMENT '最后活跃时间',
            expires_at DATETIME DEFAULT NULL COMMENT '过期时间',
            deleted_by_user TINYINT(1) DEFAULT 0 COMMENT '用户是否主动删除: 1=是',
            INDEX idx_user (user_id),
            INDEX idx_token (device_token),
            INDEX idx_active (user_id, is_active),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会话与登录日志'");

        // 预检查已有索引，避免每次都执行注定失败的 ALTER TABLE ADD INDEX
        $existingIndexes = $db->query("SHOW INDEX FROM user_sessions")->fetchAll(PDO::FETCH_COLUMN, 2);
        if ($existingIndexes === false) $existingIndexes = [];

        $neededIndexes = [
            'idx_token_active'      => "ALTER TABLE user_sessions ADD INDEX idx_token_active (device_token, is_active)",
            'idx_user_active_status'=> "ALTER TABLE user_sessions ADD INDEX idx_user_active_status (user_id, is_active, status)",
            'idx_user_loginat'      => "ALTER TABLE user_sessions ADD INDEX idx_user_loginat (user_id, login_at)",
            'idx_status_loginat'    => "ALTER TABLE user_sessions ADD INDEX idx_status_loginat (status, login_at)",
            'idx_inactive_success'  => "ALTER TABLE user_sessions ADD INDEX idx_inactive_success (is_active, status, login_at)",
        ];
        foreach ($neededIndexes as $idxName => $sql) {
            if (!in_array($idxName, $existingIndexes, true)) {
                try { $db->exec($sql); } catch (Exception $e) {}
            }
        }

        // 预检查 website_config 缺失字段，避免盲目 ALTER TABLE
        $configCols = $db->query("SHOW COLUMNS FROM website_config")->fetchAll(PDO::FETCH_COLUMN);
        if ($configCols === false) $configCols = [];

        if (!in_array('max_devices', $configCols, true)) {
            try { $db->exec("ALTER TABLE website_config ADD COLUMN max_devices INT DEFAULT 2 COMMENT '单用户最大同时在线设备数'"); } catch (Exception $e) {}
        }
        if (!in_array('remember_duration', $configCols, true)) {
            try { $db->exec("ALTER TABLE website_config ADD COLUMN remember_duration INT DEFAULT 30 COMMENT '记住我有效期（天）'"); } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        // 表创建失败，忽略
    }
}

/**
 * 从 website_config 读取配置值
 */
function getSiteConfigValue($key, $default = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT `$key` FROM website_config WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $row[$key] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * 从 User-Agent 解析设备名称
 * 如 "Chrome 120 · Windows 10"
 */
function parseDeviceName($ua) {
    $ua = $ua ?? '';
    $browser = '未知浏览器';
    $os = '未知系统';

    // 浏览器检测（按优先级，国内浏览器靠前）
    $browsers = [
        'MicroMessenger/' => '微信',
        'MQQBrowser/' => 'QQ浏览器',
        'QQBrowser/' => 'QQ浏览器',
        'UCBrowser/' => 'UC浏览器',
        'BaiduHD/' => '百度浏览器',
        'Baidu/' => '百度浏览器',
        'baiduboxapp/' => '百度App',
        'HuaweiBrowser/' => '华为浏览器',
        'ArkWeb/' => 'ArkWeb',
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
        'MSIE' => 'IE',
        'Trident/' => 'IE',
    ];
    foreach ($browsers as $needle => $name) {
        if (strpos($ua, $needle) !== false) {
            // 提取版本号
            preg_match('#' . preg_quote($needle, '#') . '([\d.]+)#', $ua, $m);
            $browser = $name . (isset($m[1]) ? ' ' . $m[1] : '');
            break;
        }
    }

    // 操作系统检测
    if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
        $winVersions = ['10.0' => '10', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
        $os = 'Windows ' . ($winVersions[$m[1]] ?? $m[1]);
    } elseif (strpos($ua, 'Mac OS X') !== false) {
        preg_match('/Mac OS X ([\d_]+)/', $ua, $m);
        $os = 'macOS ' . (isset($m[1]) ? str_replace('_', '.', $m[1]) : '');
    } elseif (preg_match('/(OpenHarmony|HarmonyOS)\s*([\d.]+)/', $ua, $m)) {
        $os = 'HarmonyOS ' . $m[2];
    } elseif (strpos($ua, 'Android') !== false) {
        preg_match('/Android ([\d.]+)/', $ua, $m);
        $os = 'Android ' . (isset($m[1]) ? $m[1] : '');
    } elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) {
        preg_match('/OS ([\d_]+)/', $ua, $m);
        $os = (strpos($ua, 'iPad') !== false ? 'iPad' : 'iPhone') . ' OS ' . (isset($m[1]) ? str_replace('_', '.', $m[1]) : '');
    } elseif (strpos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    return $browser . ' · ' . $os;
}

/**
 * 登录成功后创建设备会话
 * 处理超限踢人逻辑
 *
 * @param int  $userId     用户 ID
 * @param bool $rememberMe 是否记住我（true=长期，false=浏览器会话/短期）
 * @return string device_token
 */
function createSession($userId, $rememberMe = true) {
    $db = getDB();
    cleanupOldFailedLogs();
    ensureSessionTables();

    $maxDevices = (int)getSiteConfigValue('max_devices', 2);
    $rememberDuration = (int)getSiteConfigValue('remember_duration', 30);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceName = parseDeviceName($ua);

    // 生成设备 token
    $deviceToken = bin2hex(random_bytes(32));

    // 将该用户所有设备的 is_current 设为 0
    $stmt = $db->prepare("UPDATE user_sessions SET is_current = 0 WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$userId]);

    // 查询当前有效设备数
    $countStmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_active = 1 AND status = 'success'");
    $countStmt->execute([$userId]);
    $activeCount = (int)$countStmt->fetchColumn();

    // 超限则踢掉最旧的非当前设备
    if ($activeCount >= $maxDevices) {
        $kickStmt = $db->prepare("UPDATE user_sessions SET is_active = 0, deleted_by_user = 1
            WHERE user_id = ? AND is_active = 1 AND is_current = 0 AND status = 'success'
            ORDER BY last_active_at ASC LIMIT 1");
        $kickStmt->execute([$userId]);
    }

    // 根据 rememberMe 决定过期时间
    if ($rememberMe) {
        $cookieExpire = time() + $rememberDuration * 86400;        // 长期（例如 30 天）
        $dbExpireDays = $rememberDuration;                          // 数据库同样长期
    } else {
        $cookieExpire = 0;                                          // 浏览器会话 Cookie（关闭即失效）
        $dbExpireDays = 1;                                          // 数据库记录 1 天后过期
    }

    // 插入新会话记录
    $insertStmt = $db->prepare("INSERT INTO user_sessions
        (user_id, device_token, device_name, ip_address, user_agent, login_method,
         is_active, is_current, status, login_at, last_active_at, expires_at)
        VALUES (?, ?, ?, ?, ?, 'password', 1, 1, 'success', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))");
    $insertStmt->execute([$userId, $deviceToken, $deviceName, $ip, substr($ua, 0, 500), $dbExpireDays]);

    // 设置 Cookie
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    setcookie('device_token', $deviceToken, [
        'expires' => $cookieExpire,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    return $deviceToken;
}

/**
 * 记录登录失败
 */
function recordLoginFailure($userId, $reason = '密码错误') {
    try {
        $db = getDB();
        ensureSessionTables();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $db->prepare("INSERT INTO user_sessions
            (user_id, device_token, device_name, ip_address, user_agent, login_method,
             is_active, is_current, status, fail_reason, login_at)
            VALUES (?, '', '', ?, ?, 'password', 0, 0, 'failed', ?, NOW())");
        $stmt->execute([$userId, $ip, substr($ua, 0, 500), $reason]);
    } catch (Exception $e) {
        error_log('Record login failure error: ' . $e->getMessage());
    }
}

/**
 * 检查设备 Cookie 实现自动登录（替代原 checkRememberMe 中的 Cookie 逻辑）
 */
function checkDeviceAutoLogin() {
    if (!isset($_COOKIE['device_token'])) {
        return false;
    }

    // 封禁 IP 禁止自动登录
    if (isBotBlacklisted()) {
        setcookie('device_token', '', time() - 3600, '/');
        setcookie('nova_token', '', time() - 3600, '/');
        return false;
    }

    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT us.*, a.password, a.username, a.email, a.role, a.is_banned
            FROM user_sessions us
            JOIN admins a ON us.user_id = a.id
            WHERE us.device_token = ? AND us.is_active = 1 AND us.status = 'success'
            AND us.expires_at > NOW() AND us.deleted_by_user = 0
            ORDER BY us.login_at DESC LIMIT 1");
        $stmt->execute([$_COOKIE['device_token']]);
        $session = $stmt->fetch();

        if (!$session) {
            return false;
        }

        // 如果账号被封禁，清除 Cookie
        if (!empty($session['is_banned'])) {
            setcookie('device_token', '', time() - 3600, '/');
            setcookie('nova_token', '', time() - 3600, '/');
            return false;
        }

        // 将该用户其他设备的 is_current 设为 0
        $updateStmt = $db->prepare("UPDATE user_sessions SET is_current = 0 WHERE user_id = ? AND id != ? AND is_active = 1");
        $updateStmt->execute([$session['user_id'], $session['id']]);

        // 标记当前设备
        $updateCurrent = $db->prepare("UPDATE user_sessions SET is_current = 1, last_active_at = NOW() WHERE id = ?");
        $updateCurrent->execute([$session['id']]);

        // 更新最后登录时间
        $updateLogin = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
        $updateLogin->execute([$session['user_id']]);

        // 恢复 Session
        $_SESSION['user_id'] = $session['user_id'];
        $_SESSION['user_username'] = $session['username'];
        $_SESSION['user_email'] = $session['email'] ?? '';
        $_SESSION['user_role'] = $session['role'];

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 刷新当前设备最后活跃时间
 */
function refreshDeviceActivity() {
    if (!isset($_COOKIE['device_token'])) return;
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE user_sessions SET last_active_at = NOW() WHERE device_token = ? AND is_active = 1");
        $stmt->execute([$_COOKIE['device_token']]);
    } catch (Exception $e) {
        // 忽略
    }
}

/**
 * 用户主动下线设备（软删除）
 */
function removeUserDevice($sessionId, $userId) {
    try {
        $db = getDB();
        $currentToken = (string)($_COOKIE['device_token'] ?? '');
        if ($currentToken !== '') {
            $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, deleted_by_user = 1, is_current = 0
                WHERE id = ? AND user_id = ? AND device_token <> ? AND is_active = 1");
            $executed = $stmt->execute([$sessionId, $userId, $currentToken]);
        } else {
            $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, deleted_by_user = 1, is_current = 0
                WHERE id = ? AND user_id = ? AND is_current = 0 AND is_active = 1");
            $executed = $stmt->execute([$sessionId, $userId]);
        }
        return $executed && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('Remove user device error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 管理员强制下线指定设备
 */
function adminRemoveDevice($sessionId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE id = ? AND is_active = 1");
        return $stmt->execute([$sessionId]) && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 管理员强制下线用户所有设备
 */
function adminRemoveAllDevices($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 修改密码后使该用户所有设备失效
 */
function invalidateAllUserDevices($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        error_log('Invalidate devices error: ' . $e->getMessage());
    }
}

/**
 * 修改密码后仅使其他设备失效；没有设备 Cookie 时回退为全部失效。
 */
function invalidateOtherUserDevices($userId) {
    try {
        $db = getDB();
        $currentToken = (string)($_COOKIE['device_token'] ?? '');
        if ($currentToken !== '') {
            $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, is_current = 0
                WHERE user_id = ? AND device_token <> ? AND is_active = 1");
            $stmt->execute([$userId, $currentToken]);
        } else {
            $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, is_current = 0
                WHERE user_id = ? AND is_active = 1");
            $stmt->execute([$userId]);
        }
        return true;
    } catch (Exception $e) {
        error_log('Invalidate other devices error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 获取用户当前在线设备列表（用户视角）
 */
function getUserActiveDevices($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, device_token, device_name, ip_address, last_active_at, login_at, login_method, is_current
            FROM user_sessions
            WHERE user_id = ? AND is_active = 1 AND status = 'success' AND deleted_by_user = 0
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY is_current DESC, last_active_at DESC");
        $stmt->execute([$userId]);
        $devices = $stmt->fetchAll();
        $currentToken = (string)($_COOKIE['device_token'] ?? '');
        foreach ($devices as &$device) {
            if ($currentToken !== '') {
                $device['is_current'] = hash_equals((string)$device['device_token'], $currentToken) ? 1 : 0;
            }
            unset($device['device_token']);
        }
        unset($device);

        usort($devices, function ($left, $right) {
            if ((int)$left['is_current'] !== (int)$right['is_current']) {
                return (int)$right['is_current'] <=> (int)$left['is_current'];
            }
            return strtotime($right['last_active_at'] ?? '') <=> strtotime($left['last_active_at'] ?? '');
        });
        return $devices;
    } catch (Exception $e) {
        error_log('Get active devices error: ' . $e->getMessage());
        return [];
    }
}

/**
 * 获取用户完整登录记录（管理员视角）
 */
function getUserLoginLogs($userId, $limit = 50) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, device_name, ip_address, user_agent, login_method,
                status, fail_reason, login_at, last_active_at, is_active, is_current, deleted_by_user
            FROM user_sessions
            WHERE user_id = ?
            ORDER BY login_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 获取用户当前在线设备数（管理员视角）
 */
function getUserActiveDeviceCount($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_sessions
            WHERE user_id = ? AND is_active = 1 AND status = 'success'
            AND deleted_by_user = 0 AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 退出登录时使当前设备失效
 */
function logoutCurrentDevice($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND device_token = ? AND is_active = 1");
        $stmt->execute([$userId, $_COOKIE['device_token'] ?? '']);
    } catch (Exception $e) {
        // 忽略
    }
}

/**
 * 清理过期数据（概率触发，避免每次请求都执行）
 * - 失败记录：保留 90 天，但同用户同 IP 保留最新一条
 * - 已下线的成功记录：保留 365 天，但同用户同 IP 保留最新一条
 * - 概率：约 3% 的请求会触发清理，即平均每 ~33 次请求清理一次
 */
function cleanupOldFailedLogs() {
    // 概率控制：约 3% 触发，减少数据库压力
    if (mt_rand(1, 100) > 3) return;

    try {
        $db = getDB();

        // 封禁用户的记录不清理，保留作为审计证据
        $bannedExcluded = " AND user_id NOT IN (SELECT id FROM admins WHERE is_banned = 1)";

        // 同用户同 IP 保留最新一条的子查询
        $keepLatestIP = " AND id NOT IN (
            SELECT keep_id FROM (
                SELECT MAX(id) AS keep_id FROM user_sessions
                WHERE user_id NOT IN (SELECT id FROM admins WHERE is_banned = 1)
                GROUP BY user_id, ip_address
            ) AS t
        )";

        // 1. 清理 90 天前的失败记录（保留同用户同 IP 最新一条）
        $db->exec("DELETE FROM user_sessions
            WHERE status = 'failed' AND login_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            . $bannedExcluded . $keepLatestIP);

        // 2. 清理 1 年前已下线的成功记录（保留同用户同 IP 最新一条）
        $db->exec("DELETE FROM user_sessions
            WHERE is_active = 0 AND status = 'success'
            AND login_at < DATE_SUB(NOW(), INTERVAL 365 DAY)
            AND deleted_by_user = 1"
            . $bannedExcluded . $keepLatestIP);

        // 3. 清理过期 token 对应的僵尸记录（保留同用户同 IP 最新一条）
        $db->exec("DELETE FROM user_sessions
            WHERE is_active = 1 AND is_current = 0 AND status = 'success'
            AND expires_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            . $bannedExcluded . $keepLatestIP);
    } catch (Exception $e) {
        // 忽略
    }
}

// ========== 蜜罐系统 (Honeypot) ==========

/**
 * 输出表单蜜罐隐藏字段
 * 机器人会自动填写隐藏字段，真实用户不会看到
 *
 * @param string $name 字段名（伪装成正常字段如 website、company）
 * @return string HTML
 */
function honeypotField($name = 'website_hp') {
    $id = 'hp_' . md5($name . session_id());
    return '<div style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;overflow:hidden;" aria-hidden="true" tabindex="-1" autocomplete="off">' .
        '<label for="' . $id .">请勿填写此字段</label>" .
        '<input type="text" id="' . $id . '" name="' . htmlspecialchars($name) . '" value="" tabindex="-1" autocomplete="off">' .
        '</div>';
}

/**
 * 检查表单蜜罐是否被触发
 *
 * @param array $fields 要检查的蜜罐字段名数组，默认 ['website_hp']
 * @return bool true = 机器人触发（应拒绝提交），false = 正常
 */
function checkHoneypot($fields = ['website_hp']) {
    foreach ($fields as $field) {
        $value = trim($_POST[$field] ?? '');
        if ($value !== '') {
            // 蜜罐被触发，记录并标记该 IP
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            recordHoneypotTrigger($ip, $ua, $field, $value);
            return true;
        }
    }
    return false;
}

/**
 * 记录蜜罐触发日志并封禁 IP
 */
function recordHoneypotTrigger($ip, $ua, $trapType, $trapValue) {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO honeypot_logs (ip_address, user_agent, trap_type, trap_value, triggered_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$ip, substr($ua, 0, 500), $trapType, substr($trapValue, 0, 200)]);

        // 同一 IP 在 1 小时内触发 3 次以上，加入黑名单
        $count = $db->prepare("SELECT COUNT(*) FROM honeypot_logs WHERE ip_address = ? AND triggered_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $count->execute([$ip]);
        if ($count->fetchColumn() >= 3) {
            addBotBlacklist($ip, $ua);
        }
    } catch (Exception $e) {
        error_log('Honeypot log error: ' . $e->getMessage());
    }
}

/**
 * 将 IP 加入机器人黑名单
 */
function addBotBlacklist($ip, $reason = '') {
    try {
        $db = getDB();
        // 自动创建表
        $db->exec("CREATE TABLE IF NOT EXISTS bot_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(500),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $db->prepare(
            "INSERT INTO bot_blacklist (ip_address, reason, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)"
        );
        $stmt->execute([$ip, substr($reason, 0, 500)]);
    } catch (Exception $e) {
        error_log('Bot blacklist error: ' . $e->getMessage());
    }
}

/**
 * 检查当前 IP 是否在黑名单中
 */
function isBotBlacklisted() {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // 先检查白名单，白名单IP或UA直接放行
        if (isIpWhitelisted($ip) || isUaWhitelisted()) {
            return false;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS bot_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(500),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 管理员封禁的IP（reason含"用户封禁"）永不过期，其他IP检查过期时间
        $stmt = $db->prepare("SELECT 1 FROM bot_blacklist WHERE ip_address = ? AND (reason LIKE '%用户封禁%' OR expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
        $stmt->execute([$ip]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 自动创建蜜罐日志表
 */
function ensureHoneypotTables() {
    static $initialized = false;
    if ($initialized) return;

    try {
        $db = getDB();

        // 预检查 visit_stats 表已有字段，避免每次都执行注定失败的 ALTER TABLE
        // 独立 try-catch：即使 visit_stats 不存在也不影响后续建表
        try {
            $existingCols = $db->query("SHOW COLUMNS FROM visit_stats")->fetchAll(PDO::FETCH_COLUMN);
            if ($existingCols && !in_array('visitor_username', $existingCols, true)) {
                $db->exec("ALTER TABLE visit_stats ADD COLUMN visitor_username VARCHAR(50) DEFAULT NULL AFTER page_url");
            }
            if ($existingCols && !in_array('visitor_email', $existingCols, true)) {
                $db->exec("ALTER TABLE visit_stats ADD COLUMN visitor_email VARCHAR(100) DEFAULT NULL AFTER visitor_username");
            }
        } catch (Exception $e) {
            // visit_stats 表可能尚不存在（全新安装），静默跳过
        }

        $db->exec("CREATE TABLE IF NOT EXISTS honeypot_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500),
            trap_type VARCHAR(50) NOT NULL,
            trap_value VARCHAR(200),
            triggered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip (ip_address),
            INDEX idx_time (triggered_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->exec("CREATE TABLE IF NOT EXISTS bot_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(500),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // IP白名单表
        $db->exec("CREATE TABLE IF NOT EXISTS bot_whitelist_ip (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(500),
            added_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // UA白名单表
        $db->exec("CREATE TABLE IF NOT EXISTS bot_whitelist_ua (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ua_pattern VARCHAR(500) NOT NULL,
            reason VARCHAR(500),
            added_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ua (ua_pattern(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        error_log('Honeypot tables creation error: ' . $e->getMessage());
    }

    $initialized = true;
}

/**
 * 检查当前IP是否在白名单中
 */
function isIpWhitelisted($ip = null) {
    try {
        $db = getDB();
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($ip === 'unknown') return false;

        $stmt = $db->prepare("SELECT 1 FROM bot_whitelist_ip WHERE ip_address = ? LIMIT 1");
        $stmt->execute([$ip]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 检查当前UA是否在白名单中（支持模糊匹配）
 */
function isUaWhitelisted($ua = null) {
    try {
        $db = getDB();
        $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (empty($ua)) return false;

        // 获取所有UA白名单，逐个匹配
        $stmt = $db->query("SELECT ua_pattern FROM bot_whitelist_ua");
        $patterns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($patterns as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                return true;
            }
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

// ========== 非常用IP邮箱验证 ==========

/**
 * 检查当前IP是否为该用户的常用IP
 * 常用IP定义：近30天内该用户从该IP成功登录>=3次
 *
 * @param int $userId 用户ID
 * @param string $ip 当前IP地址
 * @return bool true=常用IP（无需验证），false=非常用IP（需要邮箱验证）
 */
function isUserFrequentIP($userId, $ip) {
    if ($ip === 'unknown' || $ip === '127.0.0.1' || $ip === '::1') {
        return true; // 本地IP跳过验证
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM user_sessions
            WHERE user_id = ? AND ip_address = ? AND status = 'success'
            AND login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$userId, $ip]);
        $count = (int)$stmt->fetchColumn();
        return $count >= 3;
    } catch (Exception $e) {
        // 出错时默认不验证，避免阻断正常登录
        return true;
    }
}

/**
 * 将某个IP标记为用户的常用IP（直接插入或增加计数记录）
 * 用于用户首次从新IP登录验证通过后，减少后续验证次数
 *
 * @param int $userId 用户ID
 * @param string $ip IP地址
 */
function markIPAsTrusted($userId, $ip) {
    try {
        $db = getDB();
        // 通过创建一条成功登录记录来增加该IP的计数
        // 实际不需要额外操作，因为 createSession 已经会记录
    } catch (Exception $e) {
        // 忽略
    }
}

require_once __DIR__ . '/account_functions.php';
