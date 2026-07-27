<?php
session_start();

// 如果未登录，重定向到登录页
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 检查爬虫日志表是否存在
function tableExists($pdo, $table) {
    // 白名单验证：只允许已知的表名
    $allowedTables = ['crawler_logs'];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }
    try {
        // 使用标识符转义（适用于MySQL）
        $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` LIMIT 1");
        $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
    return true;
}

$crawlerTableExists = tableExists($db, 'crawler_logs');

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 创建爬虫日志表
    if (isset($_POST['create_table'])) {
        $sql = "CREATE TABLE IF NOT EXISTS crawler_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            crawler_name VARCHAR(50) NOT NULL,
            user_agent TEXT NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            request_url TEXT NOT NULL,
            visit_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            status_code INT DEFAULT 200,
            INDEX (visit_time),
            INDEX (crawler_name),
            INDEX (status_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        try {
            $db->exec($sql);
            // PRG模式：成功后重定向
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } catch (Exception $e) {
            $error = "创建表失败: " . $e->getMessage();
        }
    }
    
    // 更新 robots.txt
    if (isset($_POST['update_robots'])) {
        $newRobotsContent = $_POST['robots_content'] ?? '';
        $robotsFile = '../robots.txt';
        
        // 安全验证
        // 1. 检查内容长度（robots.txt 通常不超过 100KB）
        $maxSize = 100 * 1024;
        if (strlen($newRobotsContent) > $maxSize) {
            $error = "robots.txt 内容过大，请控制在 100KB 以内";
        }
        // 2. 检查是否包含潜在危险内容（如 PHP 代码、shell 命令等）
        elseif (preg_match('/<\?php|<\?|<\s*script|shell_exec|system\(|passthru|exec\(/i', $newRobotsContent)) {
            $error = "robots.txt 包含不允许的内容";
        }
        else {
            // 使用原子写入：先写临时文件，成功后再重命名
            $tempFile = $robotsFile . '.tmp.' . uniqid();
            if (file_put_contents($tempFile, $newRobotsContent) !== false) {
                if (rename($tempFile, $robotsFile)) {
                    // PRG模式：成功后重定向，避免刷新重复提交
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                } else {
                    @unlink($tempFile); // 清理临时文件
                    $error = "robots.txt 更新失败，请检查文件权限";
                }
            } else {
                @unlink($tempFile); // 清理临时文件
                $error = "robots.txt 更新失败，请检查文件权限";
            }
        }
    }

    // 模拟爬虫访问测试
    if (isset($_POST['simulate_crawler'])) {
        $targetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // 模拟 Googlebot
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode == 200) {
            // PRG模式：成功后重定向
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $error = "模拟请求失败。状态码: $httpCode <br>错误信息: $curlError";
        }
    }
}

// 获取爬虫日志
$logs = [];
if ($crawlerTableExists) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    // 筛选
    $statusFilter = isset($_GET['status']) ? (int)$_GET['status'] : 0;
    $where = "";
    if ($statusFilter > 0) {
        $where = "WHERE status_code = ?";
    }

    // 构建查询
    $sql = "SELECT * FROM crawler_logs $where ORDER BY visit_time DESC LIMIT ? OFFSET ?";
    $params = [];
    if ($statusFilter > 0) {
        $params[] = $statusFilter;
    }
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // 获取总数
    $countSql = "SELECT COUNT(*) FROM crawler_logs $where";
    $countParams = $statusFilter > 0 ? [$statusFilter] : [];
    $totalLogs = $db->prepare($countSql);
    $totalLogs->execute($countParams);
    $totalLogs = $totalLogs->fetchColumn();
    $totalPages = ceil($totalLogs / $limit);
}

// ========== 蜜罐数据查询 ==========
ensureHoneypotTables();

// 蜜罐触发日志分页
$hp_page = max(1, intval($_GET['hp_page'] ?? 1));
$hp_per_page = 20;
$hp_offset = ($hp_page - 1) * $hp_per_page;

$hp_filter = $_GET['hp_filter'] ?? 'all';
$hp_where = '';
$hp_params = [];
if ($hp_filter === 'form') {
    $hp_where = "WHERE trap_type = 'website_hp'";
} elseif ($hp_filter === 'directory') {
    $hp_where = "WHERE trap_type = 'directory_trap'";
}

$hp_total = $db->prepare("SELECT COUNT(*) FROM honeypot_logs $hp_where");
$hp_total->execute($hp_params);
$hp_total_count = (int)$hp_total->fetchColumn();
$hp_total_pages = ceil($hp_total_count / $hp_per_page);

$hp_logs = [];
if ($hp_total_count > 0) {
    $hp_sql = "SELECT * FROM honeypot_logs $hp_where ORDER BY triggered_at DESC LIMIT $hp_per_page OFFSET $hp_offset";
    $hp_stmt = $db->prepare($hp_sql);
    $hp_stmt->execute($hp_params);
    $hp_logs = $hp_stmt->fetchAll();
}

// 封禁列表分页
$bl_page = max(1, intval($_GET['bl_page'] ?? 1));
$bl_per_page = 20;
$bl_offset = ($bl_page - 1) * $bl_per_page;

$bl_total = $db->query("SELECT COUNT(*) FROM bot_blacklist WHERE expires_at IS NULL OR expires_at > NOW()");
$bl_total_count = (int)$bl_total->fetchColumn();
$bl_total_pages = ceil($bl_total_count / $bl_per_page);

$bl_list = [];
if ($bl_total_count > 0) {
    $bl_stmt = $db->prepare("SELECT * FROM bot_blacklist WHERE expires_at IS NULL OR expires_at > NOW() ORDER BY created_at DESC LIMIT $bl_per_page OFFSET $bl_offset");
    $bl_stmt->execute();
    $bl_list = $bl_stmt->fetchAll();
}

// 处理解封操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unban_ip'])) {
    $unban_ip = trim($_POST['unban_ip'] ?? '');
    if (!empty($unban_ip)) {
        $stmt = $db->prepare("DELETE FROM bot_blacklist WHERE ip_address = ?");
        $stmt->execute([$unban_ip]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#honeypot');
        exit;
    }
}

// 处理清空蜜罐日志
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_honeypot_logs'])) {
    $db->exec("DELETE FROM honeypot_logs");
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#honeypot');
    exit;
}

// 处理清空封禁列表
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_blacklist'])) {
    $db->exec("DELETE FROM bot_blacklist");
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#honeypot');
    exit;
}

// 统计数据
$hp_today = $db->query("SELECT COUNT(*) FROM honeypot_logs WHERE DATE(triggered_at) = CURDATE()")->fetchColumn();
$hp_week = $db->query("SELECT COUNT(*) FROM honeypot_logs WHERE triggered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$hp_all = $db->query("SELECT COUNT(*) FROM honeypot_logs")->fetchColumn();
$bl_active = $db->query("SELECT COUNT(*) FROM bot_blacklist WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn();

// 概览检查
$robotsPath = '../robots.txt';
$hasRobots = file_exists($robotsPath);
$robotsContent = $hasRobots ? file_get_contents($robotsPath) : '';

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

// Sitemap 检查
$sitemapPaths = [
    '/sitemap.xml' => '../sitemap.xml',
    '/license/sitemap.php' => '../license/sitemap.php'
];
$foundSitemap = null;
$hasSitemap = false;

foreach ($sitemapPaths as $url => $path) {
    if (file_exists($path)) {
        $hasSitemap = true;
        $foundSitemap = $url;
        break;
    }
}

$page_title = 'SEO 工具集';
$extra_css = <<<'CSS'
.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}
.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
}
.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 2px solid #0d6efd;
    font-weight: bold;
}
.status-badge {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}
.status-ok { background-color: #198754; }
.status-warn { background-color: #ffc107; }
.status-err { background-color: #dc3545; }
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 text-gray-800">SEO 工具集</h1>
                        <p class="text-muted">优化网站搜索引擎表现</p>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <ul class="nav nav-tabs card-header-tabs" id="seoTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="overview-tab" data-bs-toggle="tab" href="#overview" role="tab">概览</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="analysis-tab" data-bs-toggle="tab" href="#analysis" role="tab">页面分析</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="logs-tab" data-bs-toggle="tab" href="#logs" role="tab">爬虫记录</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="schema-tab" data-bs-toggle="tab" href="#schema" role="tab">结构化数据</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="honeypot-tab" data-bs-toggle="tab" href="#honeypot" role="tab">
                                    <i class="bi bi-bug-fill"></i> 蜜罐管理
                                    <?php if ($bl_active > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $bl_active ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="seoTabsContent">
                            <!-- 概览 -->
                            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                                <h5 class="card-title mb-4">网站基本情况</h5>
                                <div class="list-group">
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="status-badge <?= $isHttps ? 'status-ok' : 'status-warn' ?>"></span>
                                            HTTPS 状态
                                        </div>
                                        <span><?= $isHttps ? '已启用' : '未启用 (建议启用)' ?></span>
                                    </div>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <span class="status-badge <?= $hasRobots ? 'status-ok' : 'status-err' ?>"></span>
                                                robots.txt 编辑
                                            </div>
                                            <div>
                                                <?php if ($hasRobots): ?>
                                                <a href="/robots.txt" target="_blank" class="btn btn-sm btn-link">查看文件</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="post" class="mt-2">
                                            <div class="mb-2">
                                                <textarea name="robots_content" class="form-control font-monospace" rows="8" placeholder="User-agent: *..."><?= e($robotsContent) ?></textarea>
                                                <div class="form-text">在此处直接编辑 robots.txt 文件内容。</div>
                                            </div>
                                            <button type="submit" name="update_robots" class="btn btn-sm btn-primary">保存更改</button>
                                        </form>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="status-badge <?= $hasSitemap ? 'status-ok' : 'status-warn' ?>"></span>
                                            站点地图 (Sitemap)
                                        </div>
                                        <div>
                                            <?php if ($hasSitemap): ?>
                                                <span class="text-success me-2">已检测到: <?= e($foundSitemap) ?></span>
                                                <a href="<?= e($foundSitemap) ?>" target="_blank" class="btn btn-sm btn-link">查看</a>
                                            <?php else: ?>
                                                <span class="text-warning">未检测到 (建议创建 sitemap.xml 或 license/sitemap.php)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="status-badge <?= !empty($config['robot_description']) ? 'status-ok' : 'status-warn' ?>"></span>
                                            网站描述 (Meta Description)
                                        </div>
                                        <span><?= !empty($config['robot_description']) ? '已设置' : '使用默认描述 (建议单独设置)' ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- 页面分析 -->
                            <div class="tab-pane fade" id="analysis" role="tabpanel">
                                <div class="alert alert-info">
                                    选择一个页面进行 SEO 分析。分析内容包括标题、描述、H 标签、图片 Alt、关键词密度等。
                                </div>
                                <div class="mb-3">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label class="form-label">页面 URL</label>
                                            <input type="text" class="form-control" id="analysisUrl" placeholder="https://..." value="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">目标关键词 (可选)</label>
                                            <input type="text" class="form-control" id="analysisKeyword" placeholder="例如：博客, 教程">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary" type="button" onclick="analyzePage()">
                                                <i class="bi bi-search"></i> 开始全面检查
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div id="analysisResult" style="display:none;">
                                    <div class="row">
                                        <div class="col-12 mb-4">
                                            <div class="card bg-light border-0">
                                                <div class="card-body text-center">
                                                    <h6 class="text-muted text-uppercase small">SEO 综合评分</h6>
                                                    <div class="display-1 fw-bold mb-2" id="seoScore">--</div>
                                                    <div class="progress" style="height: 10px; max-width: 400px; margin: 0 auto;">
                                                        <div class="progress-bar" id="seoScoreBar" role="progressbar" style="width: 0%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card h-100">
                                                <div class="card-header bg-white fw-bold"><i class="bi bi-code-slash text-primary"></i> 基础元数据</div>
                                                <div class="card-body">
                                                    <ul id="metaList" class="list-unstyled mb-0"></ul>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card h-100">
                                                <div class="card-header bg-white fw-bold"><i class="bi bi-layout-text-window-reverse text-info"></i> 内容结构</div>
                                                <div class="card-body">
                                                    <ul id="contentList" class="list-unstyled mb-0"></ul>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card h-100">
                                                <div class="card-header bg-white fw-bold"><i class="bi bi-images text-success"></i> 资源与链接</div>
                                                <div class="card-body">
                                                    <ul id="resourceList" class="list-unstyled mb-0"></ul>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card h-100">
                                                <div class="card-header bg-white fw-bold"><i class="bi bi-phone text-warning"></i> 移动端与技术</div>
                                                <div class="card-body">
                                                    <ul id="techList" class="list-unstyled mb-0"></ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 爬虫记录 -->
                            <div class="tab-pane fade" id="logs" role="tabpanel">
                                <?php if (!$crawlerTableExists): ?>
                                    <div class="text-center py-5">
                                        <h4 class="text-danger">未检测到日志表</h4>
                                        <p>需要创建数据库表来存储爬虫访问记录。</p>
                                        <form method="post">
                                            <button type="submit" name="create_table" class="btn btn-primary">立即创建表</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3 d-flex justify-content-between align-items-center">
                                        <div class="btn-group">
                                            <a href="?status=0#logs" class="btn btn-sm btn-outline-secondary <?= $statusFilter == 0 ? 'active' : '' ?>">全部</a>
                                            <a href="?status=200#logs" class="btn btn-sm btn-outline-success <?= $statusFilter == 200 ? 'active' : '' ?>">正常 (200)</a>
                                            <a href="?status=404#logs" class="btn btn-sm btn-outline-danger <?= $statusFilter == 404 ? 'active' : '' ?>">异常 (404)</a>
                                        </div>
                                        <form method="post" class="d-inline">
                                            <button type="submit" name="simulate_crawler" class="btn btn-sm btn-outline-primary" title="发送一个伪装成 Googlebot 的请求到首页">
                                                <i class="bi bi-bug"></i> 模拟爬虫访问
                                            </button>
                                        </form>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>爬虫名称</th>
                                                    <th>访问页面</th>
                                                    <th>状态码</th>
                                                    <th>IP 地址</th>
                                                    <th>时间</th>
                                                    <th>User Agent</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td><span class="badge bg-info text-dark"><?= e($log['crawler_name']) ?></span></td>
                                                    <td class="text-break"><?= e($log['request_url']) ?></td>
                                                    <td>
                                                        <?php if ($log['status_code'] >= 200 && $log['status_code'] < 300): ?>
                                                            <span class="badge bg-success"><?= $log['status_code'] ?></span>
                                                        <?php elseif ($log['status_code'] >= 400): ?>
                                                            <span class="badge bg-danger"><?= $log['status_code'] ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary"><?= $log['status_code'] ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= e($log['ip_address']) ?></td>
                                                    <td><?= e($log['visit_time']) ?></td>
                                                    <td class="small text-muted text-break" style="max-width: 200px;"><?= e($log['user_agent']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($logs)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">暂无爬虫记录</td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($statusFilter == 404): ?>
                                        <div class="alert alert-warning mt-3">
                                            <i class="bi bi-lightbulb"></i> <strong>提示：</strong> 如果发现特定 URL 频繁出现 404 错误，建议检查链接是否失效，或在服务器配置中添加 301 重定向。
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <!-- 结构化数据 -->
                            <div class="tab-pane fade" id="schema" role="tabpanel">
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle-fill"></i> Google 结构化数据 (JSON-LD) 已自动启用。
                                </div>
                                <p>系统会自动为以下内容生成结构化数据：</p>
                                <ul>
                                    <li><strong>网站首页</strong>: WebSite schema</li>
                                    <li><strong>文章页面</strong>: Article schema, BreadcrumbList</li>
                                    <li><strong>面包屑导航</strong>: BreadcrumbList</li>
                                </ul>
                                <hr>
                                <h6>验证工具</h6>
                                <p>你可以使用 Google 提供的富媒体搜索结果测试工具来验证结构化数据是否正确。</p>
                                <a href="https://search.google.com/test/rich-results" target="_blank" class="btn btn-outline-primary">打开 Google 验证工具</a>
                            </div>

                            <!-- 蜜罐管理 -->
                            <div class="tab-pane fade" id="honeypot" role="tabpanel">
                                <!-- 统计卡片 -->
                                <div class="row g-3 mb-4">
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">今日触发</div>
                                                <div class="fs-3 fw-bold text-danger"><?= $hp_today ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">近7天触发</div>
                                                <div class="fs-3 fw-bold text-warning"><?= $hp_week ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">累计触发</div>
                                                <div class="fs-3 fw-bold text-info"><?= $hp_all ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-dark text-white h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="small opacity-75">当前封禁 IP</div>
                                                <div class="fs-3 fw-bold"><?= $bl_active ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 蜜罐触发日志 -->
                                <div class="card mb-4">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0"><i class="bi bi-journal-text text-danger"></i> 触发日志</h6>
                                        <form method="post" class="d-inline" onsubmit="return confirm('确定清空所有蜜罐日志吗？')">
                                            <button type="submit" name="clear_honeypot_logs" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i> 清空日志
                                            </button>
                                        </form>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="d-flex gap-2 p-3 pb-0">
                                            <?php
                                            $hp_base = strtok($_SERVER['REQUEST_URI'], '?') . '?#honeypot';
                                            ?>
                                            <a href="<?= $hp_base ?>&hp_filter=all&hp_page=1" class="btn btn-sm <?= $hp_filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">全部</a>
                                            <a href="<?= $hp_base ?>&hp_filter=form&hp_page=1" class="btn btn-sm <?= $hp_filter === 'form' ? 'btn-primary' : 'btn-outline-secondary' ?>">表单蜜罐</a>
                                            <a href="<?= $hp_base ?>&hp_filter=directory&hp_page=1" class="btn btn-sm <?= $hp_filter === 'directory' ? 'btn-primary' : 'btn-outline-secondary' ?>">目录蜜罐</a>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>陷阱类型</th>
                                                        <th>IP 地址</th>
                                                        <th>提交值</th>
                                                        <th>时间</th>
                                                        <th>User Agent</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($hp_logs as $log): ?>
                                                    <tr>
                                                        <td>
                                                            <?php if ($log['trap_type'] === 'directory_trap'): ?>
                                                            <span class="badge bg-warning text-dark"><i class="bi bi-folder-x"></i> 目录蜜罐</span>
                                                            <?php else: ?>
                                                            <span class="badge bg-info text-dark"><i class="bi bi-input-cursor-text"></i> 表单蜜罐</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><code><?= e($log['ip_address']) ?></code></td>
                                                        <td class="text-break" style="max-width:150px;"><?= e($log['trap_value']) ?: '<span class="text-muted">-</span>' ?></td>
                                                        <td class="small text-nowrap"><?= e($log['triggered_at']) ?></td>
                                                        <td class="small text-muted text-break" style="max-width:200px;" title="<?= e($log['user_agent']) ?>"><?= e(mb_substr($log['user_agent'], 0, 50)) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($hp_logs)): ?>
                                                    <tr><td colspan="5" class="text-center text-muted py-4">暂无触发记录</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if ($hp_total_pages > 1): ?>
                                        <div class="card-footer">
                                            <nav>
                                                <ul class="pagination pagination-sm justify-content-center mb-0">
                                                    <?php if ($hp_page > 1): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="<?= $hp_base ?>&hp_filter=<?= $hp_filter ?>&hp_page=<?= $hp_page - 1 ?>">上一页</a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php
                                                    $hp_start = max(1, $hp_page - 2);
                                                    $hp_end = min($hp_total_pages, $hp_page + 2);
                                                    for ($i = $hp_start; $i <= $hp_end; $i++):
                                                    ?>
                                                    <li class="page-item <?= $i === $hp_page ? 'active' : '' ?>">
                                                        <a class="page-link" href="<?= $hp_base ?>&hp_filter=<?= $hp_filter ?>&hp_page=<?= $i ?>"><?= $i ?></a>
                                                    </li>
                                                    <?php endfor; ?>
                                                    <?php if ($hp_page < $hp_total_pages): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="<?= $hp_base ?>&hp_filter=<?= $hp_filter ?>&hp_page=<?= $hp_page + 1 ?>">下一页</a>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </nav>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- IP 封禁列表 -->
                                <div class="card">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0"><i class="bi bi-shield-lock-fill text-dark"></i> IP 封禁列表</h6>
                                        <form method="post" class="d-inline" onsubmit="return confirm('确定清空所有封禁吗？')">
                                            <button type="submit" name="clear_blacklist" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i> 清空封禁
                                            </button>
                                        </form>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>IP 地址</th>
                                                        <th>封禁原因</th>
                                                        <th>封禁时间</th>
                                                        <th>过期时间</th>
                                                        <th>操作</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($bl_list as $item): ?>
                                                    <tr>
                                                        <td><code><?= e($item['ip_address']) ?></code></td>
                                                        <td class="small text-break" style="max-width:250px;" title="<?= e($item['reason']) ?>"><?= e(mb_substr($item['reason'], 0, 60)) ?></td>
                                                        <td class="small text-nowrap"><?= e($item['created_at']) ?></td>
                                                        <td class="small text-nowrap"><?= $item['expires_at'] ? e($item['expires_at']) : '<span class="text-danger">永久</span>' ?></td>
                                                        <td>
                                                            <form method="post" class="d-inline" onsubmit="return confirm('确定解封此 IP 吗？')">
                                                                <input type="hidden" name="unban_ip" value="<?= e($item['ip_address']) ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                                    <i class="bi bi-unlock"></i> 解封
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($bl_list)): ?>
                                                    <tr><td colspan="5" class="text-center text-muted py-4">暂无封禁记录</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if ($bl_total_pages > 1): ?>
                                        <div class="card-footer">
                                            <nav>
                                                <ul class="pagination pagination-sm justify-content-center mb-0">
                                                    <?php if ($bl_page > 1): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="<?= $hp_base ?>&bl_page=<?= $bl_page - 1 ?>">上一页</a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php
                                                    $bl_start = max(1, $bl_page - 2);
                                                    $bl_end = min($bl_total_pages, $bl_page + 2);
                                                    for ($i = $bl_start; $i <= $bl_end; $i++):
                                                    ?>
                                                    <li class="page-item <?= $i === $bl_page ? 'active' : '' ?>">
                                                        <a class="page-link" href="<?= $hp_base ?>&bl_page=<?= $i ?>"><?= $i ?></a>
                                                    </li>
                                                    <?php endfor; ?>
                                                    <?php if ($bl_page < $bl_total_pages): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="<?= $hp_base ?>&bl_page=<?= $bl_page + 1 ?>">下一页</a>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </nav>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        // 激活 URL hash 对应的 Tab
        var hash = window.location.hash;
        if (hash) {
            var triggerEl = document.querySelector('#seoTabs a[href="' + hash + '"]');
            if (triggerEl) {
                var tab = new bootstrap.Tab(triggerEl);
                tab.show();
            }
        }
        
        // 监听 Tab 切换，更新 URL hash
        var tabEls = document.querySelectorAll('#seoTabs a[data-bs-toggle="tab"]');
        tabEls.forEach(function(tabEl) {
            tabEl.addEventListener('shown.bs.tab', function (event) {
                history.pushState(null, null, event.target.getAttribute('href'));
            });
        });

        function analyzePage() {
            const url = document.getElementById('analysisUrl').value;
            const keyword = document.getElementById('analysisKeyword').value.trim();
            const resultDiv = document.getElementById('analysisResult');
            
            // 清空列表
            ['metaList', 'contentList', 'resourceList', 'techList'].forEach(id => {
                document.getElementById(id).innerHTML = '<li class="text-muted"><div class="spinner-border spinner-border-sm" role="status"></div> 正在分析...</li>';
            });
            document.getElementById('seoScore').innerText = '--';
            document.getElementById('seoScoreBar').style.width = '0%';
            
            resultDiv.style.display = 'block';
            
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, "text/html");
                    
                    const metaItems = [];
                    const contentItems = [];
                    const resourceItems = [];
                    const techItems = [];
                    
                    let score = 100;
                    let deductions = 0;

                    // --- 1. 基础元数据分析 ---
                    const title = doc.querySelector('title')?.innerText || '';
                    const description = doc.querySelector('meta[name="description"]')?.content || '';
                    const keywords = doc.querySelector('meta[name="keywords"]')?.content || '';
                    const canonical = doc.querySelector('link[rel="canonical"]')?.href || '';
                    const robotsMeta = doc.querySelector('meta[name="robots"]')?.content || '';
                    const charset = doc.characterSet;

                    // Title
                    if (title.length > 0 && title.length <= 60) {
                        metaItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 标题长度适中 (' + title.length + ' 字符)<div class="small text-muted mt-1 fst-italic">"' + title + '"</div></li>');
                    } else if (title.length > 60) {
                        metaItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 标题过长 (' + title.length + ' 字符)，建议 60 字以内<div class="small text-muted mt-1 fst-italic">"' + title + '"</div></li>');
                        deductions += 5;
                    } else {
                        metaItems.push('<li class="text-danger"><i class="bi bi-x-circle"></i> 缺少标题</li>');
                        deductions += 20;
                    }
                    if (keyword) {
                        if (title.toLowerCase().includes(keyword.toLowerCase())) {
                            metaItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 标题包含目标关键词</li>');
                        } else {
                            metaItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 标题未包含目标关键词</li>');
                            deductions += 10;
                        }
                    }

                    // Description
                    if (description.length > 50 && description.length <= 160) {
                         metaItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 描述长度适中 (' + description.length + ' 字符)<div class="small text-muted mt-1 fst-italic">"' + description + '"</div></li>');
                         if (keyword) {
                             if (description.toLowerCase().includes(keyword.toLowerCase())) {
                                 metaItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 描述包含目标关键词</li>');
                             } else {
                                 metaItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 描述未包含目标关键词</li>');
                                 deductions += 5;
                             }
                         }
                    } else if (description.length > 160) {
                         metaItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 描述过长 (' + description.length + ' 字符)，建议 160 字以内<div class="small text-muted mt-1 fst-italic">"' + description + '"</div></li>');
                         deductions += 5;
                    } else if (description.length > 0) {
                        metaItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 描述过短 (' + description.length + ' 字符)<div class="small text-muted mt-1 fst-italic">"' + description + '"</div></li>');
                        deductions += 5;
                    } else {
                         metaItems.push('<li class="text-danger"><i class="bi bi-x-circle"></i> 缺少 Meta Description</li>');
                         deductions += 15;
                    }

                    // Canonical
                    if (canonical) {
                        techItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 规范标签 (Canonical) 已设置<div class="small text-muted mt-1 text-break">' + canonical + '</div></li>');
                    } else {
                        techItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 未设置 Canonical 标签</li>');
                        deductions += 5;
                    }

                    // Robots Meta
                    if (robotsMeta) {
                        techItems.push('<li class="text-info"><i class="bi bi-robot"></i> Robots Meta: ' + robotsMeta + '</li>');
                    }

                    // Charset
                    if (charset.toLowerCase() === 'utf-8') {
                        techItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 字符集 (UTF-8) 设置正确</li>');
                    } else {
                         techItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 字符集为 ' + charset + ' (建议 UTF-8)</li>');
                    }

                    // --- 2. 内容结构分析 ---
                    const h1s = doc.querySelectorAll('h1');
                    const h2s = doc.querySelectorAll('h2');
                    
                    if (h1s.length === 1) {
                        contentItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> H1 标签使用正确 (1 个)<div class="small text-muted mt-1 fst-italic">"' + h1s[0].innerText + '"</div></li>');
                        if (keyword && h1s[0].innerText.toLowerCase().includes(keyword.toLowerCase())) {
                            contentItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> H1 包含目标关键词</li>');
                        } else if (keyword) {
                            contentItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> H1 未包含目标关键词</li>');
                            deductions += 5;
                        }
                    } else if (h1s.length === 0) {
                        contentItems.push('<li class="text-danger"><i class="bi bi-x-circle"></i> 缺少 H1 标签</li>');
                        deductions += 10;
                    } else {
                        contentItems.push('<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> H1 标签过多 (' + h1s.length + ' 个)，建议每页仅使用 1 个</li>');
                        deductions += 5;
                    }
                    
                    if (h2s.length > 0) {
                        let msg = '<li class="text-success"><i class="bi bi-check-circle"></i> 页面包含 H2 副标题 (' + h2s.length + ' 个)';
                        msg += '<div class="mt-1" style="max-height: 200px; overflow-y: auto;"><ul class="small text-muted list-unstyled border-start border-success ps-2">';
                        h2s.forEach(h2 => {
                            msg += '<li class="mb-1">"' + h2.innerText + '"</li>';
                        });
                        msg += '</ul></div></li>';
                        contentItems.push(msg);
                    } else {
                        contentItems.push('<li class="text-secondary"><i class="bi bi-info-circle"></i> 未检测到 H2 标签，建议使用副标题优化结构</li>');
                        deductions += 2;
                    }

                    // 字数统计与关键词密度
                    const bodyText = doc.body.innerText;
                    const wordCount = bodyText.trim().length;
                    contentItems.push('<li class="text-info"><i class="bi bi-file-text"></i> 正文内容约 ' + wordCount + ' 字</li>');

                    if (keyword) {
                        const regex = new RegExp(keyword, 'gi');
                        const count = (bodyText.match(regex) || []).length;
                        
                        if (count > 0) {
                            const density = ((count * keyword.length) / wordCount * 100).toFixed(2);
                            contentItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 关键词出现 ' + count + ' 次 (密度: ' + density + '%)</li>');
                        } else {
                            contentItems.push('<li class="text-danger"><i class="bi bi-x-circle"></i> 内容中未找到关键词</li>');
                            deductions += 10;
                        }
                    }

                    // --- 3. 资源与链接 ---
                    const imgs = doc.querySelectorAll('img');
                    let imgsWithoutAlt = [];
                    let imgsWithoutDimensions = [];
                    
                    imgs.forEach(img => {
                        if (!img.alt) {
                            imgsWithoutAlt.push(img.src);
                        }
                        if (!img.width || !img.height) {
                            imgsWithoutDimensions.push(img.src);
                        }
                    });
                    
                    if (imgs.length > 0) {
                        if (imgsWithoutAlt.length === 0) {
                             resourceItems.push('<li class="text-success"><i class="bi bi-check-circle"></i> 所有图片 (' + imgs.length + ' 张) 都有 Alt 属性</li>');
                        } else {
                             let msg = '<li class="text-warning"><i class="bi bi-exclamation-triangle"></i> 有 ' + imgsWithoutAlt.length + ' / ' + imgs.length + ' 张图片缺少 Alt 属性';
                             msg += '<div class="mt-1" style="max-height: 200px; overflow-y: auto;"><ul class="small text-muted list-unstyled border-start border-warning ps-2">';
                             imgsWithoutAlt.forEach(src => {
                                 const fileName = src.split('/').pop() || src;
                                 msg += '<li class="text-break mb-1">' + fileName + ' <a href="' + src + '" target="_blank" class="text-decoration-none"><i class="bi bi-box-arrow-up-right" style="font-size: 0.75em;"></i></a></li>';
                             });
                             msg += '</ul></div></li>';
                             resourceItems.push(msg);
                             deductions += 5;
                        }
                        
                        if (imgsWithoutDimensions.length > 0) {
                             let msg = '<li class="text-warning"><i class="bi bi-aspect-ratio"></i> ' + imgsWithoutDimensions.length + ' 张图片缺少宽高属性 (可能影响 CLS)';
                             msg += '<div class="mt-1" style="max-height: 200px; overflow-y: auto;"><ul class="small text-muted list-unstyled border-start border-warning ps-2">';
                             imgsWithoutDimensions.forEach(src => {
                                 const fileName = src.split('/').pop() || src;
                                 msg += '<li class="text-break mb-1">' + fileName + '</li>';
                             });
                             msg += '</ul></div></li>';
                             resourceItems.push(msg);
                        }
                    } else {
                        resourceItems.push('<li class="text-secondary"><i class="bi bi-info-circle"></i> 页面无图片</li>');
                    }

                    const links = doc.querySelectorAll('a');
                    let internalLinks = [];
                    let externalLinks = [];
                    const hostname = new URL(url).hostname;
                    
                    links.forEach(link => {
                        try {
                            if (link.hostname === hostname || !link.hostname) {
                                internalLinks.push(link.href);
                            } else {
                                externalLinks.push(link.href);
                            }
                        } catch(e) {}
                    });
                    
                    resourceItems.push('<li class="text-info"><i class="bi bi-link-45deg"></i> 链接统计: 内链 ' + internalLinks.length + ' 个 / 外链 ' + externalLinks.length + ' 个</li>');
                    
                    if (externalLinks.length > 0) {
                        let msg = '<li class="text-secondary"><i class="bi bi-box-arrow-up-right"></i> 外部链接 (' + externalLinks.length + ')';
                        msg += '<div class="mt-1" style="max-height: 200px; overflow-y: auto;"><ul class="small text-muted list-unstyled border-start border-secondary ps-2">';
                        externalLinks.forEach(href => {
                            msg += '<li class="text-break mb-1"><a href="' + href + '" target="_blank" class="text-muted text-decoration-none">' + href + '</a></li>';
                        });
                        msg += '</ul></div></li>';
                        resourceItems.push(msg);
                    }


                    // --- 4. 移动端与技术 ---
                    const viewport = doc.querySelector('meta[name="viewport"]');
                    if (viewport && viewport.content.includes('width=device-width')) {
                        techItems.push('<li class="text-success"><i class="bi bi-phone"></i> 视口 (Viewport) 设置正确</li>');
                    } else {
                        techItems.push('<li class="text-danger"><i class="bi bi-phone"></i> 缺少或错误的 Viewport 设置</li>');
                        deductions += 10;
                    }

                    const ogTitle = doc.querySelector('meta[property="og:title"]');
                    const ogImage = doc.querySelector('meta[property="og:image"]');
                    if (ogTitle) {
                        let msg = '<li class="text-success"><i class="bi bi-share"></i> Open Graph 社交标签已设置';
                        if (ogImage) msg += ' (包含图片)';
                        msg += '</li>';
                        techItems.push(msg);
                    } else {
                        techItems.push('<li class="text-warning"><i class="bi bi-share"></i> 缺少 Open Graph 标签</li>');
                        deductions += 2;
                    }
                    
                    const twitterCard = doc.querySelector('meta[name="twitter:card"]');
                    const twitterImage = doc.querySelector('meta[name="twitter:image"]');
                    if (twitterCard) {
                        let msg = '<li class="text-success"><i class="bi bi-twitter"></i> Twitter Card 已设置';
                        if (twitterImage) msg += ' (包含图片)';
                        msg += '</li>';
                        techItems.push(msg);
                    } else {
                        techItems.push('<li class="text-secondary"><i class="bi bi-twitter"></i> 未设置 Twitter Card</li>');
                    }
                    
                    const favicon = doc.querySelector('link[rel="icon"]') || doc.querySelector('link[rel="shortcut icon"]');
                    const appleIcon = doc.querySelector('link[rel="apple-touch-icon"]');
                    if (favicon) {
                        let msg = '<li class="text-success"><i class="bi bi-star"></i> Favicon 已设置';
                        if (appleIcon) msg += ' (包含 Apple Touch Icon)';
                        msg += '</li>';
                        techItems.push(msg);
                    } else {
                        techItems.push('<li class="text-warning"><i class="bi bi-star"></i> 未检测到 Favicon</li>');
                        deductions += 2;
                    }

                    // 计算并显示分数
                    const finalScore = Math.max(0, score - deductions);
                    const scoreEl = document.getElementById('seoScore');
                    const scoreBar = document.getElementById('seoScoreBar');
                    
                    scoreEl.innerText = finalScore;
                    scoreBar.style.width = finalScore + '%';
                    
                    if (finalScore >= 90) {
                        scoreEl.className = 'display-1 fw-bold mb-2 text-success';
                        scoreBar.className = 'progress-bar bg-success';
                    } else if (finalScore >= 60) {
                        scoreEl.className = 'display-1 fw-bold mb-2 text-warning';
                        scoreBar.className = 'progress-bar bg-warning';
                    } else {
                        scoreEl.className = 'display-1 fw-bold mb-2 text-danger';
                        scoreBar.className = 'progress-bar bg-danger';
                    }

                    // 渲染结果
                    document.getElementById('metaList').innerHTML = metaItems.length ? metaItems.join('') : '<li class="text-muted">无数据</li>';
                    document.getElementById('contentList').innerHTML = contentItems.length ? contentItems.join('') : '<li class="text-muted">无数据</li>';
                    document.getElementById('resourceList').innerHTML = resourceItems.length ? resourceItems.join('') : '<li class="text-muted">无数据</li>';
                    document.getElementById('techList').innerHTML = techItems.length ? techItems.join('') : '<li class="text-muted">无数据</li>';
                })
                .catch(err => {
                    const errorMsg = '<li class="text-danger">分析失败: ' + err.message + ' (可能是跨域限制，请确保分析的是本站页面)</li>';
                    ['metaList', 'contentList', 'resourceList', 'techList'].forEach(id => {
                        document.getElementById(id).innerHTML = errorMsg;
                    });
                });
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
