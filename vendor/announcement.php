<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once 'public/Parsedown.php';
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 获取公告内容
$announcement = $config['website_announcement'] ?? '';

// 获取来源页面
$from = $_GET['from'] ?? '';
$backUrl = '/';
$backText = '返回首页';

if (!empty($from)) {
    $backUrl = $from;
    // 根据来源路径判断返回文字
    if (strpos($from, 'blog.php') !== false) {
        $backText = '返回博客';
    } elseif (strpos($from, 'index.php') !== false || $from === '/') {
        $backText = '返回首页';
    } elseif (strpos($from, 'friend-links.php') !== false) {
        $backText = '返回友链';
    } elseif (strpos($from, 'guestbook.php') !== false) {
        $backText = '返回留言板';
    } elseif (strpos($from, 'gallery.php') !== false) {
        $backText = '返回相册';
    } else {
        $backText = '返回上一页';
    }
}

// 解析 Markdown
$Parsedown = new Parsedown();
$announcementHtml = $Parsedown->text($announcement);

$isMobile = isMobileDevice();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站公告 - <?= e($config['website_name']) ?></title>

    <!-- SEO Meta标签 -->
    <meta name="description" content="<?= e($config['website_name']) ?> - 网站公告">
    <meta name="keywords" content="<?= e($config['website_name']) ?>,公告,通知">
    <meta name="author" content="<?= e($config['website_name']) ?>">
    <meta name="robots" content="index, follow">
    <meta http-equiv="content-language" content="zh-CN">

    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>

    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            background: url('https://api.fuchenboke.cn/api/dongman.php') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.1);
            z-index: -1;
        }

        .navbar.fixed-top {
            background: rgba(255, 255, 255, 0.85) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #dee2e6 !important;
            transition: all 0.3s ease-in-out;
        }

        .navbar-brand {
            font-weight: 600;
            color: #212529 !important;
        }

        .container-wrapper {
            padding: 100px 20px 40px;
            max-width: 900px;
            margin: 0 auto;
            min-height: calc(100vh - 100px);
            box-sizing: border-box;
        }

        .announcement-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .announcement-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #eee;
        }

        .announcement-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .announcement-header .icon {
            font-size: 3.5rem;
            color: #f59e0b;
            margin-bottom: 1rem;
            display: block;
        }

        .announcement-header .subtitle {
            color: #888;
            font-size: 1rem;
        }

        .announcement-content {
            font-size: 1.05rem;
            line-height: 1.8;
            color: #444;
        }

        .announcement-content h1,
        .announcement-content h2,
        .announcement-content h3,
        .announcement-content h4,
        .announcement-content h5,
        .announcement-content h6 {
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
            color: #333;
        }

        .announcement-content h1 { font-size: 1.8rem; }
        .announcement-content h2 { font-size: 1.6rem; }
        .announcement-content h3 { font-size: 1.4rem; }
        .announcement-content h4 { font-size: 1.2rem; }

        .announcement-content p {
            margin-bottom: 1rem;
        }

        .announcement-content ul,
        .announcement-content ol {
            margin-bottom: 1rem;
            padding-left: 2rem;
        }

        .announcement-content li {
            margin-bottom: 0.5rem;
        }

        .announcement-content blockquote {
            border-left: 4px solid #667eea;
            padding: 1rem 1.5rem;
            margin: 1.5rem 0;
            background: rgba(102, 126, 234, 0.05);
            border-radius: 0 8px 8px 0;
            color: #666;
            font-style: italic;
        }

        .announcement-content code {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.9em;
        }

        .announcement-content pre {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1rem 0;
            border: 1px solid #eee;
        }

        .announcement-content pre code {
            background: none;
            color: #333;
            padding: 0;
        }

        .announcement-content a {
            color: #667eea;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .announcement-content a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .announcement-content img {
            max-width: 100%;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .announcement-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }

        .announcement-content table th,
        .announcement-content table td {
            border: 1px solid #ddd;
            padding: 0.75rem;
            text-align: left;
        }

        .announcement-content table th {
            background: rgba(102, 126, 234, 0.1);
            font-weight: 600;
        }

        .announcement-content table tr:nth-child(even) {
            background: rgba(0,0,0,0.02);
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg,rgb(238, 246, 193) 0%,rgb(133, 209, 250) 100%);
            color: white;
            border: none;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 2rem;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .empty-announcement {
            text-align: center;
            padding: 3rem 1rem;
            color: #888;
        }

        .empty-announcement i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #ccc;
        }

        .empty-announcement h4 {
            color: #666;
            margin-bottom: 0.5rem;
        }

        /* 导航栏滚动效果 */
        .navbar.fixed-top.scrolled {
            background: rgba(255, 255, 255, 0.95) !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        /* 响应式 */
        @media (max-width: 768px) {
            .announcement-card {
                padding: 25px;
            }

            .announcement-header h1 {
                font-size: 1.5rem;
            }

            .announcement-header .icon {
                font-size: 2.5rem;
            }

            .announcement-content {
                font-size: 1rem;
            }
        }

        /* 页脚样式 */
        footer {
            background-color: #ffffff !important;
            color: #6c757d !important;
            border-top: 1px solid #dee2e6 !important;
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
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-megaphone-fill me-2 text-primary"></i><?= e($config['website_name']) ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/">首页</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/blog.php">博客</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/vendor/shuoshuo.php">说说</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/vendor/gallery.php">相册</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/vendor/guestbook.php">留言</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/vendor/friend-links.php">友链</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active text-danger" href="/vendor/announcement.php">公告</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="container container-wrapper">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="announcement-card">
                    <div class="announcement-header">
                        <i class="bi bi-megaphone-fill icon"></i>
                        <h1>网站公告</h1>
                        <p class="subtitle">最新动态和重要通知</p>
                    </div>

                    <?php if (!empty($announcement)): ?>
                        <div class="announcement-content">
                            <?= $announcementHtml ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-announcement">
                            <i class="bi bi-inbox"></i>
                            <h4>暂无公告</h4>
                            <p>管理员还没有发布任何公告，请稍后再来查看~</p>
                        </div>
                    <?php endif; ?>

                    <div class="text-center">
                        <a href="<?= e($backUrl) ?>" class="back-btn">
                            <i class="bi bi-arrow-left"></i><?= e($backText) ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // 导航栏滚动效果
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar.fixed-top');
            if (window.scrollY > 10) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
        
        // 获取当前公告日期（如果存在）并标记为已读
        <?php if (!empty($announcement) && !empty($config['website_announcement_date'])): ?>
        (function() {
            var announcementDate = <?= strtotime($config['website_announcement_date']) ?>;
            var currentUsername = '<?= e($_SESSION['user_username'] ?? '') ?>';
            
            // 只有已登录用户才记录
            if (!currentUsername) return;
            
            var storageKey = 'announcement_viewed_date_' + currentUsername;
            localStorage.setItem(storageKey, announcementDate.toString());
        })();
        <?php endif; ?>
    </script>
</body>
</html>
