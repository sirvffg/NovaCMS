<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// 获取目标URL参数
$url = isset($_GET['url']) ? $_GET['url'] : '';
$title = isset($_GET['title']) ? $_GET['title'] : '外部链接';
$delay = isset($_GET['delay']) ? (int)$_GET['delay'] : 5; // 默认5秒后跳转

// 验证URL是否有效
if (empty($url)) {
    header('Location: /');
    exit;
}

// 安全验证：防止开放重定向攻击
function isValidRedirectUrl($url) {
    // 1. 基础清理
    $url = trim($url);

    // 2. 禁止危险协议（防止 XSS）
    if (preg_match('/^\s*(javascript|data|vbscript|file|ftp):/i', $url)) {
        return false;
    }

    // 3. 验证URL格式
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    // 4. 解析URL
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
        return false;
    }

    // 5. 允许的顶级域名白名单（防止跳转到钓鱼网站）
    // 可以根据需要添加更多受信任的域名
    $allowedTlds = [
        'com', 'net', 'org', 'edu', 'gov', 'cn', 'io', 'co',
        'cc', 'info', 'biz', 'xyz', 'top', 'site', 'online',
        'tech', 'dev', 'app', 'blog', 'me', 'tv', 'gg', 'cloud'
    ];

    $host = strtolower($parsed['host']);
    $hostParts = explode('.', $host);
    $tld = end($hostParts);

    if (!in_array($tld, $allowedTlds, true)) {
        return false;
    }

    // 6. 禁止跳转到本站（防止循环）
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === $currentHost || strpos($host, '.' . $currentHost) !== false) {
        // 如果是本站，直接重定向而不显示中间页
        header('Location: ' . $url);
        exit;
    }

    // 7. 禁止IP地址跳转
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }

    // 8. 禁止内网地址
    $privatePatterns = [
        '/^10\./',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
        '/^192\.168\./',
        '/^127\./',
        '/^localhost$/i',
        '/^0\.0\.0\.0$/'
    ];
    foreach ($privatePatterns as $pattern) {
        if (preg_match($pattern, $host)) {
            return false;
        }
    }

    return true;
}

// 验证URL是否有效（基础验证）
if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    header('Location: /');
    exit;
}

$parsed = parse_url($url);
$targetHost = isset($parsed['host']) ? strtolower($parsed['host']) : '';
$targetPath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '/';
$targetQuery = isset($parsed['query']) ? $parsed['query'] : '';

// 获取工作室配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 检查目标URL是否在白名单中（优先检查，绕过安全限制）
$whitelist = !empty($config['redirect_whitelist']) ? trim($config['redirect_whitelist']) : '';
$whitelistItems = array_filter(array_map('trim', explode("\n", $whitelist)), function($item) {
    return !empty(trim($item));
});

$inWhitelist = false;
if (!empty($whitelistItems) && !empty($targetHost)) {
    // 规范化目标路径（去除末尾斜杠，转小写）
    $targetPathNorm = strtolower(rtrim($targetPath, '/') ?: '/');
    
    foreach ($whitelistItems as $item) {
        $item = strtolower(trim($item));
        if (empty($item)) continue;
        
        // 如果是完整URL
        if (strpos($item, 'http://') === 0 || strpos($item, 'https://') === 0) {
            $itemParsed = parse_url($item);
            $itemHost = isset($itemParsed['host']) ? strtolower($itemParsed['host']) : '';
            
            // 域名必须匹配
            if ($itemHost !== $targetHost) continue;
            
            // 规范化路径（去除末尾斜杠）
            $itemPathNorm = isset($itemParsed['path']) ? strtolower(rtrim($itemParsed['path'], '/') ?: '/') : '/';
            $itemQuery = isset($itemParsed['query']) ? $itemParsed['query'] : '';
            
            // 域名匹配 + 路径匹配
            if ($itemPathNorm === $targetPathNorm) {
                // 无查询参数时直接匹配，有查询参数时需要检查
                if (empty($itemQuery)) {
                    $inWhitelist = true;
                    break;
                } else {
                    // 解析查询参数进行比较（忽略参数顺序）
                    parse_str($itemQuery, $itemParams);
                    parse_str($targetQuery, $targetParams);
                    if ($itemParams === $targetParams) {
                        $inWhitelist = true;
                        break;
                    }
                }
            }
        } else {
            // 纯域名匹配（支持子域名）
            if ($targetHost === $item || substr($targetHost, -strlen('.' . $item)) === '.' . $item) {
                $inWhitelist = true;
                break;
            }
        }
    }
}

// 如果在白名单中，直接跳转
if ($inWhitelist) {
    header('Location: ' . $url);
    exit;
}

// 继续执行安全验证
if (!isValidRedirectUrl($url)) {
    $error = '跳转地址无效或不安全，请检查链接是否正确。';
    header('Location: /?error=' . urlencode($error));
    exit;
}

// 记录跳转访问
recordVisit($_SERVER['REQUEST_URI']);

$isMobile = isMobileDevice();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>即将跳转至 <?= e($title) ?> - <?= e($config['website_name']) ?></title>
    
    <!-- SEO Meta标签 -->
    <meta name="description" content="您即将离开本站，跳转到外部链接">
    <meta name="robots" content="noindex, nofollow">
    
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/harmonyos-sans.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4895ef;
            --text-color: #2b2d42;
            --bg-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'HarmonyOS Sans', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 20px;
            color: var(--text-color);
        }

        .redirect-card {
            max-width: 600px;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            position: relative;
        }

        .redirect-header {
            background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .redirect-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .redirect-header i {
            font-size: 4rem;
            margin-bottom: 15px;
            display: inline-block;
            position: relative;
            z-index: 1;
        }
        
        .redirect-header h2 {
            margin: 0;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }
        
        .redirect-header p {
            margin-top: 10px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        .redirect-body {
            padding: 40px;
        }
        
        .target-url-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
            margin: 25px 0;
            word-break: break-all;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .target-url-box i {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-top: -2px;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 12px;
            padding: 14px 30px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }
        
        .btn-outline-secondary {
            border-radius: 12px;
            padding: 14px 30px;
            font-weight: 600;
        }

        .security-notice {
            background-color: #fff8e1;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #856404;
            margin-bottom: 30px;
        }

        .back-link {
            position: absolute;
            top: 30px;
            left: 30px;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 10;
            transition: color 0.2s;
        }
        
        .back-link:hover {
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            body {
                padding: 15px;
                align-items: flex-start;
                padding-top: 60px;
            }
            .redirect-card {
                margin: 0;
                border-radius: 20px;
            }
            .redirect-header {
                padding: 25px 20px;
            }
            .redirect-header i {
                font-size: 3rem;
            }
            .redirect-header h2 {
                font-size: 1.5rem;
            }
            .redirect-body {
                padding: 25px 20px;
            }
            .target-url-box {
                padding: 15px;
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 10px;
            }
            .security-notice {
                padding: 12px;
                font-size: 0.85rem;
            }
            .d-flex.justify-content-center.gap-3 {
                flex-direction: column;
            }
            .d-flex.justify-content-center.gap-3 .btn {
                width: 100%;
                padding: 12px 20px;
            }
            .back-link {
                top: 15px;
                left: 15px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 380px) {
            .redirect-header i {
                font-size: 2.5rem;
            }
            .redirect-header h2 {
                font-size: 1.3rem;
            }
            .redirect-body {
                padding: 20px 15px;
            }
            h4.fw-bold {
                font-size: 1.1rem;
            }
            .target-url-box {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <a href="/" class="back-link">
        <i class="bi bi-arrow-left"></i>
        <span>返回首页</span>
    </a>

    <div class="redirect-card">
        <div class="redirect-header">
            <i class="bi bi-box-arrow-up-right"></i>
            <h2>即将离开本站</h2>
            <p>您即将跳转到外部链接</p>
        </div>
        
        <div class="redirect-body">
            <div class="text-center mb-2">
                <h4 class="fw-bold"><?= e($title) ?></h4>
            </div>
            
            <div class="target-url-box">
                <i class="bi bi-link-45deg"></i>
                <div>
                    <div class="text-muted small mb-1">目标链接</div>
                    <div class="fw-medium text-break"><?= e($url) ?></div>
                </div>
            </div>
            
            <div class="security-notice">
                <div class="d-flex">
                    <i class="bi bi-shield-exclamation me-2" style="font-size: 1.2rem;"></i>
                    <div>
                        <strong>安全提示：</strong> 您即将离开 <?= e($config['website_name']) ?>。请注意，我们不对第三方网站的内容负责，请谨慎访问。
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-center gap-3">
                <a href="/" class="btn btn-outline-secondary">
                    取消访问
                </a>
                <a href="<?= e($url) ?>" class="btn btn-primary" rel="noopener noreferrer">
                    继续访问 <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
