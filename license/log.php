<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
recordVisit($_SERVER['REQUEST_URI']);

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$pageTitle = '建站日志 - ' . e($config['website_name']);

$logs = [];
try {
    $db->query("SELECT 1 FROM website_logs LIMIT 1");
    $logs = $db->query("SELECT * FROM website_logs ORDER BY date DESC, id DESC")->fetchAll();
} catch (Exception $e) {
    $logs = [
        ['date' => '2024-05-20', 'version' => 'v1.0.0', 'title' => '网站正式上线', 'content' => '个人博客系统上线。', 'type' => 'release'],
        ['date' => '2024-06-01', 'version' => 'v1.1.0', 'title' => '新增RSS订阅功能', 'content' => '新增RSS订阅源功能。', 'type' => 'update']
    ];
}


// 每个类型对应的颜色和图标
$typeMeta = [
    'release' => ['color' => '#22c55e', 'icon' => 'rocket-takeoff', 'label' => '发布'],
    'update'  => ['color' => '#3b82f6', 'icon' => 'arrow-up-circle', 'label' => '更新'],
    'fix'     => ['color' => '#ef4444', 'icon' => 'bug', 'label' => '修复'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?></title>
<meta name="description" content="<?= e($config['website_name']) ?> 的建站日志，记录网站更新与重要事件">
<?php if (!empty($config['favicon'])): 
$faviconUrl = $config['favicon']; 
if (!preg_match('/^https?:\/\//', $faviconUrl) && strpos($faviconUrl,'/')!==0) $faviconUrl = '/' . $faviconUrl;
?>
<link rel="icon" href="<?= e($faviconUrl) ?>">
<link rel="shortcut icon" href="<?= e($faviconUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
<?php endif; ?>

<link href="/assets/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
<link href="/assets/css/style.css" rel="stylesheet">

<style>
:root {
    --primary: #4f46e5;
    --primary-light: #6366f1;
    --primary-bg: #eef2ff;
    --text: #1e293b;
    --text-secondary: #64748b;
    --text-muted: #94a3b8;
    --border: #e2e8f0;
    --bg: #f8fafc;
    --card-bg: #ffffff;
    --radius: 14px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
    --shadow: 0 4px 12px rgba(0,0,0,0.04), 0 1px 3px rgba(0,0,0,0.05);
    --shadow-lg: 0 12px 32px rgba(0,0,0,0.06), 0 2px 8px rgba(0,0,0,0.04);
    --transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Navbar */
.navbar.fixed-top {
    background: rgba(255,255,255,0.85) !important;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 8px 0 !important;
    box-shadow: var(--shadow-sm);
}
.navbar-brand {
    color: var(--text) !important;
    font-weight: 700;
    font-size: 1.05rem !important;
    letter-spacing: -0.3px;
}
.navbar .nav-link {
    color: var(--text-secondary) !important;
    font-weight: 500;
    font-size: 0.9rem;
    transition: color var(--transition);
}
.navbar .nav-link:hover { color: var(--primary) !important; }
.navbar .btn {
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.4rem 0.9rem;
    border-radius: 8px;
    transition: all var(--transition);
}
.navbar .btn-outline-primary {
    color: var(--primary);
    border-color: var(--primary);
}
.navbar .btn-outline-primary:hover {
    background: var(--primary);
    color: #fff;
}

/* Page Container */
.page-container {
    flex: 1;
    padding-top: 72px;
}
.content-wrapper {
    max-width: 900px;
    margin: 0 auto;
    padding: 32px 24px 40px;
}

/* Section Header */
.section-header {
    text-align: center;
    padding: 20px 0 32px;
}
.section-icon {
    width: 56px;
    height: 56px;
    background: var(--primary-bg);
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.section-icon i {
    font-size: 1.6rem;
    color: var(--primary);
}
.section-header h1 {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}
.section-header p {
    color: var(--text-secondary);
    font-size: 0.95rem;
    margin: 0 auto;
    line-height: 1.6;
}

/* Timeline */
.timeline {
    position: relative;
    padding-left: 48px;
}
.timeline::before {
    content: '';
    position: absolute;
    top: 8px;
    bottom: 8px;
    left: 19px;
    width: 2px;
    background: var(--border);
    border-radius: 1px;
}

/* Timeline Card */
.timeline-card {
    position: relative;
    margin-bottom: 20px;
    background: var(--card-bg);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    transition: all var(--transition);
}
.timeline-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

/* Timeline Dot */
.timeline-dot {
    position: absolute;
    left: -36px;
    top: 20px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: #fff;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

/* Card Body */
.timeline-body {
    padding: 18px 22px;
}

.timeline-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.timeline-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.72rem;
    font-weight: 600;
    color: #fff;
}

.timeline-date {
    font-size: 0.82rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 4px;
}

.timeline-version {
    font-size: 0.72rem;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
    font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
}

.timeline-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
}

.timeline-content {
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.7;
    padding: 12px 16px;
    background: #f8fafc;
    border-radius: 10px;
    margin-top: 8px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 64px 20px;
}
.empty-state .empty-icon {
    font-size: 3.5rem;
    color: var(--text-muted);
    margin-bottom: 16px;
    display: block;
}
.empty-state h3 {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
}
.empty-state p {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

/* Footer */
footer {
    background: var(--card-bg);
    border-top: 1px solid var(--border);
    padding: 12px 0;
    font-size: 0.8rem;
    color: var(--text-muted);
}
footer a { color: var(--text-muted); text-decoration: none; }
footer a:hover { color: var(--primary); }

/* Responsive */
@media (max-width: 768px) {
    .content-wrapper { padding: 20px 14px 32px; }
    .timeline { padding-left: 40px; }
    .timeline-dot { left: -30px; width: 30px; height: 30px; font-size: 0.85rem; }
    .timeline::before { left: 14px; }
    .timeline-body { padding: 14px 16px; }
    .section-header { padding: 12px 0 24px; }
    .section-header h1 { font-size: 1.4rem; }
}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light fixed-top">
    <div class="container">
        <a class="navbar-brand" href="/"><?= e($config['website_name']) ?></a>
        <div class="ms-auto">
            <a class="btn btn-outline-primary btn-sm" href="/">
                <i class="bi bi-house me-1"></i>返回首页
            </a>
        </div>
    </div>
</nav>

<div class="page-container">
    <div class="content-wrapper">

        <!-- Section Header -->
        <div class="section-header">
            <div class="section-icon">
                <i class="bi bi-journal-text"></i>
            </div>
            <h1>建站日志</h1>
            <p>记录网站更新与重要事件</p>
        </div>

        <!-- Timeline -->
        <div class="timeline">
            <?php if (!empty($logs)): ?>
                <?php foreach($logs as $log):
                    $type = $log['type'] ?? 'update';
                    $meta = $typeMeta[$type] ?? $typeMeta['update'];
                    $dateFormatted = date('Y/m/d', strtotime($log['date']));
                ?>
                <div class="timeline-card">
                    <div class="timeline-dot" style="background:<?= $meta['color'] ?>;">
                        <i class="bi bi-<?= $meta['icon'] ?>"></i>
                    </div>
                    <div class="timeline-body">
                        <div class="timeline-meta">
                            <span class="timeline-badge" style="background:<?= $meta['color'] ?>;">
                                <?= $meta['label'] ?>
                            </span>
                            <span class="timeline-date">
                                <i class="bi bi-calendar3"></i> <?= $dateFormatted ?>
                            </span>
                            <?php if (!empty($log['version'])): ?>
                            <span class="timeline-version" style="background:<?= $meta['color'] ?>15;color:<?= $meta['color'] ?>;">
                                <?= e($log['version']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-title"><?= e($log['title']) ?></div>
                        <div class="timeline-content"><?= nl2br(e($log['content'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-journal empty-icon"></i>
                    <h3>暂无更新日志</h3>
                    <p>还没有发布过任何更新记录</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../vendor/footer.php'; ?>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
