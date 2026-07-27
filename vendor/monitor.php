<?php
// 简单的网站状态监控页面 - 模仿 Nezha 风格
// 放置于 vendor/monitor.php

// 1. 处理 AJAX 请求 (用于获取状态)
if (isset($_GET['action']) && $_GET['action'] === 'check') {
    header('Content-Type: application/json');
    $url = $_GET['url'] ?? '';
    $name = $_GET['name'] ?? 'Unknown';

    if (empty($url)) {
        echo json_encode(['status' => 'error', 'message' => 'URL is required']);
        exit;
    }

    // 安全验证：防止SSRF攻击
    require_once '../config/functions.php';
    $validatedUrl = validateUrl($url, ['http', 'https']);

    if ($validatedUrl === false) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or unauthorized URL']);
        exit;
    }

    $url = $validatedUrl; // 使用验证后的URL

    // 简单的连通性测试和延时计算
    $start = microtime(true);
    $online = false;
    $latency = 0;

    // 尝试解析 URL
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? $url;
    $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
    $scheme = $parsed['scheme'] ?? 'http';

    // 使用 fsockopen 进行快速 TCP 连接测试
    try {
        $fp = @fsockopen($host, $port, $errno, $errstr, 2); // 2秒超时
        if ($fp) {
            $online = true;
            $latency = round((microtime(true) - $start) * 1000); // 毫秒
            fclose($fp);
        }
    } catch (Exception $e) {
        $online = false;
    }

    echo json_encode([
        'name' => $name,
        'online' => $online,
        'latency' => $latency,
        'url' => $url
    ]);
    exit;
}

// 2. 页面配置
require_once '../config/database.php';
require_once '../config/functions.php';
recordVisit($_SERVER['REQUEST_URI']);
$db = getDB();

// 获取网站配置
$siteConfig = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$config = $siteConfig; // 为 footer.php 提供变量
$siteName = $siteConfig['website_name'] ?? '服务监控';
$siteFavicon = $siteConfig['favicon'] ?? '';

// 开启会话以检查管理员登录状态
session_start();
$isAdmin = isset($_SESSION['admin_id']);

// 监控列表配置
$monitors = $db->query("SELECT * FROM server_monitors ORDER BY sort_order ASC, id ASC")->fetchAll();

// 如果数据库为空，提供默认值
if (empty($monitors)) {
    // 获取当前网站域名
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $current_host = $_SERVER['HTTP_HOST'];
    $current_site_url = "$protocol://$current_host";
    
    $monitors = [
        [
            'name' => '本站服务器',
            'url' => $current_site_url,
            'location' => 'CN',
            'type' => 'Web'
        ]
    ];
}


?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName) ?> - 服务监控</title>
    <meta name="description" content="实时监控 <?= htmlspecialchars($siteName) ?> 的服务运行状态。">
    <meta property="og:title" content="<?= htmlspecialchars($siteName) ?> - 服务监控">
    <meta property="og:description" content="实时监控 <?= htmlspecialchars($siteName) ?> 的服务运行状态。">
    <meta property="og:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <link rel="canonical" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') ?>">
    <?php if (!empty($siteFavicon)): ?>
    <link rel="icon" href="<?= htmlspecialchars($siteFavicon) ?>" type="image/x-icon">
    <link rel="shortcut icon" href="<?= htmlspecialchars($siteFavicon) ?>" type="image/x-icon">
    <?php else: ?>
    <link rel="icon" href="/assets/images/favicon.png" type="image/png">
    <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png">
    <?php endif; ?>
    <!-- 引入 Bootstrap CSS -->
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- 引入 Font Awesome (Local) -->
    <link href="/assets/css/all.min.css" rel="stylesheet">
    <!-- 引入 Google Fonts (Local CSS) -->
    <link href="/assets/css/inter.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-light: #e0e7ff;
            --success-color: #10b981;
            --success-bg: #d1fae5;
            --warning-color: #f59e0b;
            --warning-bg: #fef3c7;
            --danger-color: #ef4444;
            --danger-bg: #fee2e2;
            --text-main: #1f2937;
            --text-secondary: #6b7280;
            --bg-body: #f3f4f6;
            --bg-card: #ffffff;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
            display: flex;
            flex-direction: column;
        }

        .page-content {
            flex: 1 0 auto;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            background: var(--bg-card);
            padding: 1.5rem 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
        }

        .page-title i {
            color: var(--primary-color);
        }

        .server-time {
            font-family: 'Monaco', 'Consolas', monospace;
            background: var(--primary-light);
            color: var(--primary-color);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Overview Cards */
        .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-info h3 {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin: 0 0 0.25rem 0;
            font-weight: 500;
        }

        .stat-info .value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1;
        }

        .bg-blue-light { background: #eff6ff; color: #3b82f6; }
        .bg-green-light { background: #ecfdf5; color: #10b981; }
        .bg-red-light { background: #fef2f2; color: #ef4444; }
        .bg-gray-light { background: #f3f4f6; color: #6b7280; }

        /* Server List */
        .monitor-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .monitor-card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 1.25rem 2rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }

        .monitor-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateX(4px);
        }

        .monitor-card.status-online { border-left-color: var(--success-color); }
        .monitor-card.status-offline { border-left-color: var(--danger-color); }

        .monitor-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex: 1;
        }

        .monitor-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-size: 1.1rem;
        }

        .monitor-details h4 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .monitor-details .url {
            margin: 0.25rem 0 0 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .monitor-stats {
            display: flex;
            align-items: center;
            gap: 2.5rem;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            min-width: 80px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge.online { background: var(--success-bg); color: var(--success-color); }
        .status-badge.offline { background: var(--danger-bg); color: var(--danger-color); }
        .status-badge.pending { background: var(--bg-body); color: var(--text-secondary); }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: currentColor;
        }

        .latency-value {
            font-weight: 600;
            font-family: 'Monaco', monospace;
        }

        .latency-fast { color: var(--success-color); }
        .latency-medium { color: var(--warning-color); }
        .latency-slow { color: var(--danger-color); }

        .location-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-main);
        }

        /* Pulse Animation */
        .pulse {
            animation: pulse-animation 2s infinite;
        }

        @keyframes pulse-animation {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        /* Floating Action Button */
        .fab-back {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 3.5rem;
            height: 3.5rem;
            background: var(--bg-card);
            border-radius: 50%;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-size: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            z-index: 100;
        }

        .fab-back:hover {
            transform: scale(1.1) rotate(-90deg);
            color: var(--primary-color);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        @media (max-width: 768px) {
            .monitor-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.5rem;
                padding: 1.5rem;
            }
            
            .monitor-stats {
                width: 100%;
                justify-content: space-between;
                gap: 1rem;
            }

            .stat-item {
                align-items: flex-start;
                min-width: auto;
            }
        }

        /* 页脚样式 */
        footer {
            background-color: #ffffff !important;
            color: #6c757d !important;
            border-top: 1px solid #dee2e6 !important;
            flex-shrink: 0;
        }

        footer .footer-links a,
        footer .footer-extra a {
            color: #6c757d;
            text-decoration: none;
            transition: color 0.2s;
        }

        footer .footer-links a:hover,
        footer .footer-extra a:hover {
            color: #0d6efd;
        }

        footer .footer-info {
            color: #6c757d;
        }
    </style>
</head>
<body>

<div class="page-content">
    <div class="main-container">
    <!-- Header -->
    <div class="page-header">
        <h1 class="page-title">
        <i class="fa-solid fa-server"></i>
        <span><?= htmlspecialchars($siteName) ?> 服务监控</span>
    </h1>
        <div class="server-time">
            <i class="fa-regular fa-clock me-2"></i><span id="current-time">--:--:--</span>
        </div>
    </div>

    <!-- Overview Stats -->
    <div class="overview-grid">
        <div class="stat-card">
            <div class="stat-info">
                <h3>监控总数</h3>
                <div class="value" id="total-count">-</div>
            </div>
            <div class="stat-icon bg-blue-light">
                <i class="fa-solid fa-layer-group"></i>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>运行正常</h3>
                <div class="value" id="online-count">-</div>
            </div>
            <div class="stat-icon bg-green-light">
                <i class="fa-solid fa-check-circle"></i>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>服务异常</h3>
                <div class="value" id="offline-count">-</div>
            </div>
            <div class="stat-icon bg-red-light">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>最后更新</h3>
                <div class="value" style="font-size: 1.1rem; font-weight: 600;" id="last-updated">-</div>
            </div>
            <div class="stat-icon bg-gray-light">
                <i class="fa-solid fa-rotate"></i>
            </div>
        </div>
    </div>

    <!-- Monitor List -->
    <div class="monitor-list">
        <?php foreach ($monitors as $index => $monitor): ?>
        <div class="monitor-card monitor-item" 
             id="monitor-<?= $index ?>" 
             data-url="<?= htmlspecialchars($monitor['url']) ?>" 
             data-name="<?= htmlspecialchars($monitor['name']) ?>">
            
            <div class="monitor-info">
                <div class="monitor-icon">
                    <?php if($monitor['type'] == 'Web'): ?>
                        <i class="fa-solid fa-globe"></i>
                    <?php elseif($monitor['type'] == 'API'): ?>
                        <i class="fa-solid fa-code"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-server"></i>
                    <?php endif; ?>
                </div>
                <div class="monitor-details">
                    <h4><?= htmlspecialchars($monitor['name']) ?></h4>
                    <?php if ($isAdmin): ?>
                    <div class="url">
                        <i class="fa-solid fa-link fa-xs"></i>
                        <?= htmlspecialchars($monitor['url']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="monitor-stats">
                <div class="stat-item">
                    <span class="stat-label">地区</span>
                    <div class="location-tag">
                        <?php 
                        $flag = 'server';
                        $locName = '未知';
                        if($monitor['location'] == 'CN') { $flag = 'flag text-danger'; $locName = 'CN'; }
                        elseif($monitor['location'] == 'HK') { $flag = 'flag text-danger'; $locName = 'HK'; }
                        elseif($monitor['location'] == 'US') { $flag = 'flag text-primary'; $locName = 'US'; }
                        elseif($monitor['location'] == 'JP') { $flag = 'flag text-danger'; $locName = 'JP'; }
                        ?>
                        <i class="fa-solid fa-<?= $flag ?>"></i> <?= $locName ?>
                    </div>
                </div>

                <div class="stat-item">
                    <span class="stat-label">延迟</span>
                    <div class="latency-value latency-text">-</div>
                </div>

                <div class="stat-item">
                    <span class="stat-label">状态</span>
                    <div class="status-badge pending status-text pulse">
                        <span class="dot"></span> 检测中
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</div>

<a href="/" class="fab-back" title="返回首页">
    <i class="fa-solid fa-arrow-left"></i>
</a>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
    // 时间更新
    function updateTime() {
        const now = new Date();
        document.getElementById('current-time').textContent = now.toLocaleTimeString('zh-CN', {hour12: false});
    }
    setInterval(updateTime, 1000);
    updateTime();

    // 监控核心逻辑
    const monitors = document.querySelectorAll('.monitor-item');
    let totalCount = monitors.length;
    let onlineCount = 0;
    let offlineCount = 0;

    document.getElementById('total-count').textContent = totalCount;

    function updateOverview() {
        document.getElementById('online-count').textContent = onlineCount;
        document.getElementById('offline-count').textContent = offlineCount;
        document.getElementById('last-updated').textContent = new Date().toLocaleTimeString('zh-CN', {hour12: false});
    }

    function updateCardStatus(element, isOnline, latency) {
        const statusText = element.querySelector('.status-text');
        const latencyText = element.querySelector('.latency-text');
        
        statusText.classList.remove('pulse', 'pending', 'online', 'offline');
        element.classList.remove('status-online', 'status-offline');

        if (isOnline) {
            statusText.classList.add('online');
            statusText.innerHTML = '<span class="dot"></span> 正常';
            element.classList.add('status-online');
            
            let latencyClass = 'latency-fast';
            if (latency > 150) latencyClass = 'latency-medium';
            if (latency > 400) latencyClass = 'latency-slow';
            
            latencyText.innerHTML = `<span class="${latencyClass}">${latency}ms</span>`;
        } else {
            statusText.classList.add('offline');
            statusText.innerHTML = '<span class="dot"></span> 离线';
            element.classList.add('status-offline');
            latencyText.innerHTML = '<span class="text-muted">Timeout</span>';
        }
    }

    function checkStatus(element) {
        const url = element.dataset.url;
        const name = element.dataset.name;
        
        fetch(`?action=check&url=${encodeURIComponent(url)}&name=${encodeURIComponent(name)}`)
            .then(res => res.json())
            .then(data => {
                const wasChecked = element.dataset.checked === "true";
                const wasOnline = element.dataset.isOnline === "true";
                
                updateCardStatus(element, data.online, data.latency);
                
                // 计数逻辑
                if (!wasChecked) {
                    if (data.online) onlineCount++;
                    else offlineCount++;
                    element.dataset.checked = "true";
                } else if (wasOnline !== data.online) {
                    if (data.online) { onlineCount++; offlineCount--; }
                    else { onlineCount--; offlineCount++; }
                }
                
                element.dataset.isOnline = data.online ? "true" : "false";
                updateOverview();
            })
            .catch(err => {
                console.error(err);
                updateCardStatus(element, false, 0);
                
                if (element.dataset.checked !== "true") {
                    offlineCount++;
                    element.dataset.checked = "true";
                } else if (element.dataset.isOnline === "true") {
                    onlineCount--;
                    offlineCount++;
                }
                element.dataset.isOnline = "false";
                updateOverview();
            });
    }

    // 初始化检测
    document.addEventListener('DOMContentLoaded', () => {
        monitors.forEach((monitor, index) => {
            setTimeout(() => {
                checkStatus(monitor);
            }, index * 150); // 错峰请求
        });
    });

    // 轮询
    setInterval(() => {
        monitors.forEach((monitor, index) => {
            setTimeout(() => {
                checkStatus(monitor);
            }, index * 150);
        });
    }, 30000); // 30秒轮询
</script>
</body>
</html>