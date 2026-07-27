 <?php
session_start();

// =============================================
// Nova JSON API 路由拦截
// 路径如 /nova-json/v1/posts → 转发到 vendor/nova-json/init.php
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
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 获取最新博客
$posts = $db->query("SELECT * FROM blog_posts WHERE is_published = 1 ORDER BY created_at DESC LIMIT 3")->fetchAll();

// 获取主页小站链接
$homeLinks = [];
try {
    $homeLinks = $db->query("SELECT * FROM home_links WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
}

// 获取个人项目
$myProjects = [];
try {
    $myProjects = $db->query("SELECT * FROM my_projects WHERE is_active=1 ORDER BY sort_order ASC, id DESC")->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
}

// 引入音乐播放器组件
require_once 'config/music_player.php';

// 引入图床映射类（前台只读版本）
require_once 'config/image_mapper.php';

$isMobile = isMobileDevice();

// 图床配置
$imageBedEnabled = !empty($config['image_bed_display_enabled']) && $config['image_bed_display_enabled'] == 1;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <script>
        // 执行主题设置
        (function() {
            const storedTheme = localStorage.getItem('theme');
            if (storedTheme) {
                document.documentElement.setAttribute('data-bs-theme', storedTheme);
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首页 - <?= e($config['website_name']) ?></title>

    <!-- SEO Meta标签 -->
    <meta name="description" content="<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>">
    <meta name="keywords" content="<?= e($config['website_name']) ?>,博客,技术分享,个人网站,编程,生活">
    <meta name="author" content="<?= e($config['website_name']) ?>">
    <meta name="robots" content="index, follow">
    <meta http-equiv="content-language" content="zh-CN">
    <?php if (!empty($config['bing_verification'])): ?>
    <meta name="msvalidate.01" content="<?= e($config['bing_verification']) ?>" />
    <?php endif; ?>
    <?php if (!empty($config['logo'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>">
    <?php endif; ?>

    <!-- JSON-LD 结构化数据 (增强 Google 收录) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>",
      "name": "<?= e($config['website_name']) ?>",
      "author": {
          "@type": "Person",
          "name": "<?= e($config['website_author'] ?? $config['website_name']) ?>"
      },
      "description": "<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>",
      <?php if (!empty($config['logo'])): ?>
      "image": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>",
      <?php endif; ?>
      "potentialAction": {
        "@type": "SearchAction",
        "target": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/blog.php?q={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
    </script>
    
    <!-- 移动端/PWA 优化 -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="format-detection" content="telephone=no">

    <!-- QQ/微信/小红书/抖音 通用优化 (Schema.org Microdata) -->
    <meta itemprop="name" content="<?= e($config['website_name']) ?>">
    <meta itemprop="description" content="<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>">
    <?php if (!empty($config['favicon'])): ?>
    <meta itemprop="image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    
    <!-- Open Graph / Facebook / 微信朋友圈 -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($config['website_name']) ?>">
    <meta property="og:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>">
    <meta property="og:title" content="<?= e($config['website_name']) ?>">
    <meta property="og:description" content="<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>">
    <?php if (!empty($config['logo'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>">
    <?php endif; ?>
    <?php if (!empty($config['favicon'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <meta property="og:image:width" content="300">
    <meta property="og:image:height" content="300">
    <?php endif; ?>
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>">
    <meta name="twitter:title" content="<?= e($config['website_name']) ?>">
    <meta name="twitter:description" content="<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>">
    <?php if (!empty($config['favicon'])): ?>
    <meta name="twitter:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    
    <!-- 图标设置 (覆盖全平台) -->
    <?php if (!empty($config['favicon'])): ?>
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <link rel="icon" href="<?= e($config['favicon']) ?>" type="image/x-icon">
    <link rel="apple-touch-icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <!-- Font Awesome (Local) -->
    <link rel="stylesheet" href="<?= getResourceUrl('/assets/css/all.min.css', 'https://cdn.staticfile.net/font-awesome/6.5.1/css/all.min.css') ?>">
    <link href="/assets/css/style.css" rel="stylesheet">
    <!-- 引入艺术字体 (本地) -->
    <?php renderMusicPlayerCSS($config); ?>
    
    <!-- 从 index.php 提取的静态 CSS，外部化后可被浏览器缓存 -->
    <link href="/assets/css/inline-extra.css?v=20260630" rel="stylesheet">
    <style>
    /* 预加载首屏关键图片 (减少 LCP) — 动态背景图需保持内联 */
    .hero-section {
        background-image: url('<?= !empty($config['home_bg_image']) ? e($config['home_bg_image']) : "https://bing.img.run/rand.php" ?>');
    }
    </style>

    <!-- 结构化数据 JSON-LD -->
    <script type="application/ld+json">
    [
        {
            "@context": "https://schema.org",
            "@type": "WebSite",
            "name": "<?= e($config['website_name']) ?>",
            "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>",
            "potentialAction": {
                "@type": "SearchAction",
                "target": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?search={search_term_string}' ?>",
                "query-input": "required name=search_term_string"
            }
        },
        {
            "@context": "https://schema.org",
            "@type": "Organization",
            "name": "<?= e($config['website_name']) ?>",
            "description": "<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 200))) ?>",
            "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>",
            <?php if (!empty($config['logo'])): ?>
            "logo": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>",
            <?php endif; ?>
            <?php if (!empty($config['contact_email'])): ?>
            "email": "<?= e($config['contact_email']) ?>",
            <?php endif; ?>
            "sameAs": []
        },
        {
            "@context": "https://schema.org",
            "@type": "ItemList",
            "itemListElement": [
                {
                    "@type": "SiteNavigationElement",
                    "position": 1,
                    "name": "博客文章",
                    "description": "探索技术教程、编程心得与生活随笔",
                    "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php' ?>"
                },
                {
                    "@type": "SiteNavigationElement",
                    "position": 2,
                    "name": "摄影相册",
                    "description": "记录生活中的美好瞬间与风景",
                    "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/vendor/gallery.php' ?>"
                },
                {
                    "@type": "SiteNavigationElement",
                    "position": 3,
                    "name": "友情链接",
                    "description": "发现更多优秀的独立博客与伙伴",
                    "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/vendor/friend-links.php' ?>"
                },
                {
                    "@type": "SiteNavigationElement",
                    "position": 4,
                    "name": "留言互动",
                    "description": "欢迎留下您的足迹与建议",
                    "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/vendor/guestbook.php' ?>"
                }
            ]
        }
    ]
    </script>
    
    <?php if (!empty($posts)): ?>
    <!-- 博客文章列表结构化数据 -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Blog",
        "name": "<?= e($config['website_name']) ?> 博客",
        "description": "<?= e($config['website_name']) ?> 的最新博客文章",
        "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php' ?>",
        "blogPost": [
            <?php foreach ($posts as $index => $post): ?>
            {
                "@type": "BlogPosting",
                "headline": "<?= e($post['title']) ?>",
                "author": {
                    "@type": "Person",
                    "name": "<?= e($post['author']) ?>"
                },
                "datePublished": "<?= date('c', strtotime($post['created_at'])) ?>",
                "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?id=' . $post['id'] ?>",
                "description": "<?= e(mb_substr(strip_tags($post['content']), 0, 160)) ?>"
            }<?= $index < count($posts) - 1 ? ',' : '' ?>
            <?php endforeach; ?>
        ]
    }
    </script>
    <?php endif; ?>
    
        <!-- 引入中文艺术字体 — 动态加载，保持内联 -->
    <style>
        <?php $zmxUrl = getResourceUrl('', 'https://fonts.loli.net/css2?family=Zhi+Mang+Xing&display=swap'); ?>
        <?php if ($zmxUrl): ?>
        @import url('<?= $zmxUrl ?>');
        <?php else: ?>
        @font-face {
            font-family: 'Zhi Mang Xing';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/assets/fonts/MaShanZheng/MaShanZheng-Regular.ttf') format('truetype');
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg fixed-top navbar-custom">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center artistic-font" href="/" style="font-size: 1.5rem;">
                <?= e($config['website_name']) ?>
            </a>
            
            <!-- 移动端主题切换开关 (只在移动端显示) -->
            <div class="theme-switch-wrapper d-lg-none ms-auto me-2">
                <label class="theme-switch" for="theme-checkbox-mobile">
                    <input type="checkbox" id="theme-checkbox-mobile" />
                    <div class="slider">
                        <i class="bi bi-sun-fill icon sun"></i>
                        <i class="bi bi-moon-fill icon moon"></i>
                    </div>
                </label>
            </div>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" style="border: none; padding: 4px 8px; background: rgba(255,255,255,0.3); border-radius: 6px;">
                <i class="bi bi-list text-white fs-3"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="/blog.php">博客</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="spaceDropdown" role="button" aria-expanded="false">
                            <i class="bi bi-stars me-1"></i>空间
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="spaceDropdown">
                            <li><a class="dropdown-item" href="/vendor/friend-links.php"><i class="bi bi-link-45deg me-2"></i>友链</a></li>
                            <li><a class="dropdown-item" href="/vendor/shuoshuo.php"><i class="bi bi-chat-quote me-2"></i>说说</a></li>
                            <li><a class="dropdown-item" href="/vendor/gallery.php"><i class="bi bi-images me-2"></i>相册</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="otherDropdown" role="button" aria-expanded="false">
                            <i class="bi bi-send me-1"></i>其他
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="otherDropdown">
                            <li><a class="dropdown-item" href="/vendor/monitor.php"><i class="bi bi-speedometer2 me-2"></i>服务监控</a></li>
                            <li><a class="dropdown-item" href="/license/terms.php"><i class="bi bi-file-earmark-text me-2"></i>协议</a></li>
                            <li><a class="dropdown-item" href="/license/rss.php"><i class="bi bi-rss me-2"></i>RSS</a></li>
                            <li><a class="dropdown-item" href="/license/sitemap.php"><i class="bi bi-map me-2"></i>Sitemap</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/vendor/guestbook.php">
                            <i class="bi bi-chat-text me-1"></i>留言板
                        </a>
                    </li>

                    <!-- 主题切换开关 (只在桌面端显示) -->
                    <li class="nav-item d-none d-lg-flex align-items-center">
                        <div class="theme-switch-wrapper ms-2">
                            <label class="theme-switch" for="theme-checkbox">
                                <input type="checkbox" id="theme-checkbox" />
                                <div class="slider">
                                    <i class="bi bi-sun-fill icon sun"></i>
                                    <i class="bi bi-moon-fill icon moon"></i>
                                </div>
                            </label>
                        </div>
                    </li>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- 已登录用户菜单 -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="userDropdown" role="button">
                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_username'] ?? 'User') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text">
                                <?php if (($_SESSION['user_role'] ?? 'user') === 'admin'): ?>
                                <span class="badge bg-danger">管理员</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">普通用户</span>
                                <?php endif; ?>
                            </span></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (($_SESSION['user_role'] ?? 'user') === 'admin'): ?>
                            <li><a class="dropdown-item" href="/admin"><i class="bi bi-gear"></i> 管理后台</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="/vendor/profile.php"><i class="bi bi-person"></i> 个人中心</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="logout">
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right"></i> 退出登录</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <!-- 未登录用户 -->
                    <!-- 桌面端显示按钮 -->
                    <li class="nav-item d-none d-lg-flex align-items-center">
                        <a href="/vendor/login.php" class="btn btn-outline-light btn-sm me-2">登录</a>
                    </li>
                    <li class="nav-item d-none d-lg-flex align-items-center">
                        <a href="/vendor/register.php" class="btn btn-primary btn-sm">注册</a>
                    </li>
                    
                    <!-- 移动端显示普通链接 -->
                    <li class="nav-item d-lg-none">
                        <a href="/vendor/login.php" class="nav-link"><i class="bi bi-box-arrow-in-right me-1"></i> 登录</a>
                    </li>
                    <li class="nav-item d-lg-none">
                        <a href="/vendor/register.php" class="nav-link"><i class="bi bi-person-plus me-1"></i> 注册</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 首页横幅 -->
    <section id="home" class="hero-section d-flex position-relative" style="padding-top: 120px;">
        <!-- 加载指示器 -->
        <div id="bgLoading" class="loading-overlay" style="display: none;">
            <div class="loading-content">
                <div class="modern-loader"></div>
                <div class="loading-text">Loading</div>
            </div>
        </div>
        
        <!-- 背景图片层 - 底部（当前显示） -->
        <div id="bgImage" class="hero-bg-media hero-bg-image" 
             data-custom-image="<?= !empty($config['home_bg_image']) ? e($config['home_bg_image']) : '' ?>"
             data-use-bing="<?= !empty($config['use_bing_bg']) && $config['use_bing_bg'] == 1 ? '1' : '0' ?>"
             data-bing-api="<?= !empty($config['bing_api']) ? e($config['bing_api']) : '' ?>"
             data-is-mobile="<?= $isMobile ? '1' : '0' ?>"
             style="z-index: -3; <?php 
                 if (!empty($config['home_bg_image'])) {
                     echo 'background-image: url(\'' . e($config['home_bg_image']) . '\');';
                 } else {
                    echo 'background-color: var(--bs-body-bg); transition: background-color 0.3s ease;';
                }
            ?>">
        </div>

        <!-- 背景图片层 - 顶部（用于淡入新图片） -->
        <div id="bgImageTransition" class="hero-bg-media hero-bg-image" style="z-index: -2; opacity: 0;"></div>
        
        <!-- 背景视频层 -->
        <?php if (!empty($config['home_bg_video'])): ?>
        <video id="bgVideo" class="hero-bg-media hero-bg-video" autoplay muted playsinline style="z-index: -1;">
            <source src="<?= e($config['home_bg_video']) ?>" type="video/mp4">
        </video>
        <?php endif; ?>
        
        <!-- 遮罩层 -->
        <div class="hero-overlay"></div>
        
        <div class="container position-relative z-3">
            <div class="row align-items-center mb-5">
                <!-- 左侧内容 -->
                <div class="col-lg-8 text-white text-start mb-5 mb-lg-0" style="margin-top: -80px;">
                    
                    <!-- 头像 -->
                    <div class="avatar-container position-relative d-inline-block mb-3">
                        <div class="avatar-glow" style="width: 160px; height: 160px;"></div>
                        <img id="heroAvatar" src="/assets/images/default-avatar.png" alt="<?= e($config['website_author'] ?? $config['website_name']) ?> Avatar" class="rounded-circle position-relative z-2 shadow-lg" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid rgba(255,255,255,0.2);">
                    </div>

                    <!-- 模仿图片样式的新简介部分 -->
                    <div class="mb-0">
                        <h1 class="display-5 fw-bold mb-2">
                            你好，我是 <span class="text-white artistic-font"><?= e($config['website_author'] ?? $config['website_name']) ?></span>
                        </h1>
                        <div class="fs-5 text-white-50 lh-lg">
                            <div class="mb-2">
                                <div class="intro-capsule">
                                    <span class="intro-text" id="introText" data-text="<?= e(getIntroText($config['website_intro'])) ?>"></span>
                                </div>
                            </div>
                        </div>
                    </div>



                    <!-- 苹果风格毛玻璃卡片 -->
                    <div class="apple-glass-card mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-stars text-warning fs-5 me-2"></i>
                                <h5 class="mb-0">About Me</h5>
                            </div>
                            <span class="about-more-btn" onclick="showAboutModal()">查看更多 <i class="bi bi-arrow-right-short"></i></span>
                        </div>
                        <div class="about-content" id="about-content">
                        <?php if (empty($config['website_description'])): ?>
                        <p>
                            热爱编程，追求极致。在这里分享技术见解与生活感悟。
                            <span class="d-block mt-2 text-white-50" style="font-size: 0.85rem;">Coding life, sharing ideas.</span>
                        </p>
                        <?php else: ?>
                        <!-- SEO: 预先输出纯文本内容，供搜索引擎抓取 -->
                        <div class="d-none d-md-none">
                            <?= strip_tags(htmlspecialchars_decode($config['website_description'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($config['website_description'])): ?>
                    <script type="text/markdown" id="about-markdown-data"><?= $config['website_description'] ?></script>
                    <?php endif; ?>
                    </div>

                    <!-- 博客与联系按钮 -->
                    <div class="d-flex flex-wrap gap-3 mt-3">
                        <div class="position-relative btn-with-preview">
                            <a href="/blog.php" class="btn btn-primary-glass rounded-pill px-4 py-2 d-flex align-items-center">
                                <i class="bi bi-book me-2"></i>My Blog
                            </a>
                            <?php if (!empty($posts) && isset($posts[0])): ?>
                            <!-- 最新文章预览气泡 -->
                            <div class="blog-preview-popover">
                                <div class="text-uppercase text-primary small fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 1px;">Latest Post</div>
                                <a href="/blog.php?id=<?= $posts[0]['id'] ?>" class="preview-title"><?= e($posts[0]['title']) ?></a>
                                <div class="preview-meta">
                                    <i class="bi bi-calendar3 me-1"></i><?= date('M d, Y', strtotime($posts[0]['created_at'])) ?>
                                </div>
                                <p class="preview-excerpt">
                                    <?= e(mb_substr(strip_tags($posts[0]['content']), 0, 80)) ?>...
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <a href="mailto:<?= e($config['contact_email']) ?>" class="btn btn-glass rounded-pill px-4 py-2 d-flex align-items-center">
                            <i class="bi bi-envelope me-2"></i>Contact Me
                        </a>
                        
                        <?php if (!empty($config['website_announcement']) && !empty($config['website_announcement_enable'])): ?>
                        <?php 
                        // 传递公告日期和用户名到前端用于判断
                        $announcementDate = !empty($config['website_announcement_date']) ? strtotime($config['website_announcement_date']) : 0;
                        $currentUsername = isset($_SESSION['user_id']) ? $_SESSION['user_username'] : '';
                        ?>
                        <div class="position-relative btn-with-preview" id="announcement-wrapper">
                            <a href="/vendor/announcement.php?from=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-glass rounded-pill px-4 py-2 d-flex align-items-center" id="announcement-btn">
                                <span class="announcement-gradient">🎊号外:有新公告喽</span>
                            </a>
                            <!-- 公告预览气泡 -->
                            <div class="blog-preview-popover">
                                <div class="text-uppercase text-warning small fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 1px;">📢 最新公告</div>
                                <a href="/vendor/announcement.php?from=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="preview-title">点击查看详情</a>
                                <div class="preview-meta">
                                    <i class="bi bi-megaphone me-1"></i>重要通知
                                </div>
                                <p class="preview-excerpt">
                                    <?= e(mb_substr(strip_tags($config['website_announcement']), 0, 80)) ?>...
                                </p>
                            </div>
                        </div>
                        <?php if (!empty($currentUsername)): ?>
                        <script>
                        (function() {
                            var announcementDate = <?= $announcementDate ?>;
                            var currentUsername = '<?= e($currentUsername) ?>';
                            var storageKey = 'announcement_viewed_date_' + currentUsername;
                            
                            // 获取用户已查看的公告日期
                            var viewedDate = parseInt(localStorage.getItem(storageKey) || '0');
                            
                            // 如果数据库时间戳与本地存储时间戳不同，显示按钮；相同则隐藏
                            if (announcementDate === viewedDate) {
                                document.getElementById('announcement-wrapper').style.display = 'none';
                            }
                            
                            // 点击公告后更新已查看日期
                            document.getElementById('announcement-btn').addEventListener('click', function() {
                                localStorage.setItem(storageKey, announcementDate.toString());
                            });
                        })();
                        </script>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- 社交媒体图标 -->
                    <div class="d-flex gap-3 mt-3 flex-wrap align-items-center">
                        <?php if (!empty($config['social_github'])): ?>
                        <a href="https://github.com/<?= e($config['social_github']) ?>" target="_blank" class="social-icon" title="GitHub">
                            <i class="fa-brands fa-github"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['contact_qq'])): ?>
                        <a href="javascript:void(0)" class="social-icon" title="QQ: <?= e($config['contact_qq']) ?>" data-bs-toggle="modal" data-bs-target="#qqModal">
                            <i class="fa-brands fa-qq"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_wechat'])): ?>
                        <a href="javascript:void(0)" class="social-icon" title="WeChat: <?= e($config['social_wechat']) ?>" data-bs-toggle="modal" data-bs-target="#wechatModal">
                            <i class="fa-brands fa-weixin"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_bilibili'])): ?>
                        <a href="https://space.bilibili.com/<?= e($config['social_bilibili']) ?>" target="_blank" class="social-icon" title="Bilibili">
                            <i class="fa-brands fa-bilibili"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($config['social_douyin'])): ?>
                        <a href="https://www.douyin.com/search/<?= e($config['social_douyin']) ?>?source=normal_search&type=user" target="_blank" class="social-icon" title="Douyin">
                            <i class="fa-brands fa-tiktok"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_kuaishou'])): ?>
                        <a href="https://www.kuaishou.com/profile/<?= e($config['social_kuaishou']) ?>" target="_blank" class="social-icon" title="Kuaishou">
                            <i class="fa-solid fa-video"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_xiaohongshu'])): ?>
                        <a href="https://www.xiaohongshu.com/user/profile/<?= e($config['social_xiaohongshu']) ?>" target="_blank" class="social-icon" title="Xiaohongshu">
                            <i class="fa-solid fa-book"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_whatsapp'])): ?>
                        <a href="https://wa.me/<?= e($config['social_whatsapp']) ?>" target="_blank" class="social-icon" title="WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_x'])): ?>
                        <a href="https://x.com/<?= e($config['social_x']) ?>" target="_blank" class="social-icon" title="X (Twitter)">
                            <i class="fa-brands fa-twitter"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_discord'])): ?>
                        <a href="https://discord.gg/<?= e($config['social_discord']) ?>" target="_blank" class="social-icon" title="Discord">
                            <i class="fa-brands fa-discord"></i>
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($config['social_youtube'])): ?>
                        <a href="https://www.youtube.com/@<?= e($config['social_youtube']) ?>" target="_blank" class="social-icon" title="YouTube">
                            <i class="fa-brands fa-youtube"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sphere + Clock Area -->
                <div class="col-lg-4 d-none d-lg-block fade-in-up" style="animation-delay: 0.2s;">
                    <div class="position-relative overflow-hidden w-100 d-flex align-items-center justify-content-center" style="min-height: 500px; margin-top: 0px;">
                        <!-- Sphere Container -->
                        <div id="sphere-container" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; transform: translateY(-60px);"></div>
                        
                        <!-- Clock Canvas -->
                        <canvas id="clock-canvas" style="position: relative; z-index: 10; pointer-events: none; width: 100%; max-width: 350px; transform: translateY(-60px);"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 向下滚动提示 -->
        <div class="scroll-down-indicator" onclick="window.scrollTo({top: window.innerHeight, behavior: 'smooth'})">
            <span class="custom-arrow-down"></span>
        </div>
    </section>

    <!-- 首页横幅 -->
    <!-- Removed hero section as per user request -->



    <!-- About Me Section -->
    <section class="py-5 position-relative z-3" id="about-me">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold mb-3 text-white">About Me</h2>
                <div class="mx-auto bg-primary rounded" style="width: 80px; height: 5px;"></div>
            </div>

            <div class="row align-items-center gy-5">
                <div class="col-lg-6 order-2 order-lg-1 text-white">
                    <div class="about-me-content fs-5 lh-lg" id="about-me-detail">
                        <!-- Content rendered by JS -->
                    </div>
                    <script type="text/markdown" id="about-me-markdown"><?= $config['website_detail'] ?? '' ?></script>
                    
                </div>
                
                <div class="col-lg-6 order-1 order-lg-2">
                    <div class="position-relative px-lg-5">
                        <?php if (!empty($config['logo'])): ?>
                        <div class="ratio ratio-4x3">
                            <img src="<?= e($config['logo']) ?>" alt="About Me" class="img-fluid rounded-4 shadow-lg w-100 h-100 object-fit-cover">
                        </div>
                        <?php else: ?>
                        <div class="bg-light rounded-4 shadow-lg d-flex align-items-center justify-content-center ratio ratio-4x3">
                            <i class="bi bi-image fs-1 text-muted"></i>
                        </div>
                        <?php endif; ?>
                        
                        <div class="position-absolute bottom-0 end-0 mb-3 me-3 bg-body p-3 rounded-3 shadow border d-flex align-items-center gap-2">
                            <span class="spinner-grow spinner-grow-sm text-success" role="status" aria-hidden="true"></span>
                            <span class="fw-bold small">Running...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 主页小站链接 -->
    <?php if (!empty($homeLinks)): ?>
    <section class="py-5 position-relative z-3">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">Links</h2>
                <p class="text-white">我的小站链接，欢迎到访哦！</p>
            </div>
            
            <div class="links-grid">
                <?php foreach ($homeLinks as $link): ?>
                <div>
                    <div class="card h-100 border-0 shadow-sm hover-lift glass-card-light">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-3">
                                <?php 
                                    $icon = $link['icon'];
                                    if (preg_match('/^(http|\/)/', $icon)): 
                                ?>
                                    <img src="<?= e($icon) ?>" class="rounded-circle me-3" style="width: 48px; height: 48px; object-fit: cover;">
                                <?php elseif (!empty($icon)): ?>
                                    <div class="icon-square bg-primary bg-opacity-10 text-primary rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                        <i class="<?= e($icon) ?> fs-4"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="icon-square bg-primary bg-opacity-10 text-primary rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                        <i class="bi bi-link-45deg fs-4"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div>
                                    <h5 class="card-title fw-bold mb-0 text-dark"><?= e($link['name']) ?></h5>
                                </div>
                            </div>
                            
                            <p class="card-text text-muted small mb-4" style="min-height: 40px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                <?= e($link['description']) ?>
                            </p>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <?php if (!empty($link['badge_text'])): ?>
                                <span class="badge bg-<?= e($link['badge_color'] ?? 'primary') ?> bg-opacity-10 text-<?= e($link['badge_color'] ?? 'primary') ?> rounded-pill px-3">
                                    <?= e($link['badge_text']) ?>
                                </span>
                                <?php else: ?>
                                <span></span>
                                <?php endif; ?>
                                
                                <a href="<?= e($link['url']) ?>" target="_blank" class="text-secondary stretched-link">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- 个人项目 -->
    <?php if (!empty($myProjects)): ?>
    <section class="py-5 position-relative z-3">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">My Project</h2>
                <p class="text-white-50">我的一些个人项目</p>
            </div>
            
            <div class="cards-grid">
                <?php foreach ($myProjects as $project): ?>
                <div>
                    <div class="card h-100 border-0 shadow-sm hover-lift glass-card overflow-hidden">
                        <!-- 项目封面图 -->
                        <?php if (!empty($project['icon'])): ?>
                        <div class="position-relative ratio ratio-16x9">
                            <img src="<?= e($project['icon']) ?>" class="object-fit-cover w-100 h-100" alt="<?= e($project['name']) ?>">
                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-end" style="background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0) 80%);">
                                <div class="p-3 w-100">
                                    <h5 class="fw-bold text-white mb-1"><?= e($project['name']) ?></h5>
                                    <p class="text-white-50 small mb-0 text-truncate"><?= e($project['description']) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="card-body d-flex flex-column p-3">
                            <?php if (empty($project['icon'])): ?>
                            <div class="mb-2">
                                <h5 class="fw-bold text-white mb-1"><?= e($project['name']) ?></h5>
                            </div>
                            <?php endif; ?>
                            
                            <p class="card-text text-white-50 mb-3 small flex-grow-1 line-clamp-3">
                                <?= e($project['description']) ?>
                            </p>
                            
                            <div class="mb-3">
                                <?php 
                                if (!empty($project['tags'])):
                                    $tags = preg_split('/[,，]/', $project['tags']);
                                    foreach ($tags as $tag):
                                        $tag = trim($tag);
                                        if (empty($tag)) continue;
                                ?>
                                <span class="badge bg-primary bg-opacity-10 text-white border border-primary border-opacity-25 me-1 mb-1" style="font-size: 0.75rem;"><?= e($tag) ?></span>
                                <?php endforeach; endif; ?>
                            </div>
                            
                            <div class="mt-auto pt-3 border-top border-white border-opacity-10 d-flex justify-content-between align-items-center">
                                <a href="<?= e($project['url']) ?>" target="_blank" class="text-decoration-none fw-bold text-white d-flex align-items-center" style="font-size: 0.9rem;">
                                    查看项目 <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                                <small class="text-white-50" style="font-size: 0.8rem;"><?= e($project['start_date']) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- 最新博客文章 -->
    <?php if (!empty($posts)): ?>
    <section class="py-5 position-relative z-3">
        <div class="container">
            <h2 class="text-center mb-5 fw-bold text-white">最新文章</h2>
            <div class="cards-grid">
                <?php foreach ($posts as $post): ?>
                <?php $coverUrl = !empty($post['cover_image']) ? ImageMapper::getFinalUrl($post['cover_image'], $imageBedEnabled) : ''; ?>
                <div>
                    <div class="card h-100 border-0 shadow-sm hover-lift glass-card">
                        <?php if (!empty($coverUrl)): ?>
                        <a href="/blog.php?id=<?= $post['id'] ?>" class="ratio ratio-16x9 d-block overflow-hidden rounded-top">
                            <img src="<?= e($coverUrl) ?>" class="w-100 h-100 object-fit-cover transition-transform" alt="<?= e($post['title']) ?>" loading="lazy">
                        </a>
                        <?php endif; ?>
                        <div class="card-body">
                            <div class="mb-2 text-white-50 small">
                                <i class="bi bi-calendar3 me-1"></i><?= date('Y-m-d', strtotime($post['created_at'])) ?>
                                <span class="mx-2">|</span>
                                <i class="bi bi-person me-1"></i><?= e($post['author']) ?>
                            </div>
                            <h5 class="card-title fw-bold">
                                <a href="/blog.php?id=<?= $post['id'] ?>" class="text-decoration-none text-white stretched-link">
                                    <?= e($post['title']) ?>
                                </a>
                            </h5>
                            <p class="card-text text-white-50 small line-clamp-3">
                                <?= e(mb_substr(strip_tags($post['content']), 0, 100)) ?>...
                            </p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-5">
                <a href="/blog.php" class="btn btn-outline-light rounded-pill px-4">
                    查看更多文章 <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- About Me 模态框 -->
    <div id="aboutModal" class="about-modal" onclick="closeAboutModal(event)">
        <div class="about-modal-dialog" onclick="event.stopPropagation()">
            <div class="about-modal-header">
                <h5 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill me-2"></i>About Me</h5>
                <button class="about-modal-close" onclick="closeAboutModal()">&times;</button>
            </div>
            <div class="about-modal-body" id="about-modal-content">
                <!-- 内容将由 JS 填充 -->
            </div>
        </div>
    </div>
    
    <!-- 新年祝福弹窗 -->
    <?php if (!empty($config['newyear_enable'])): ?>
    <div id="newyearModal" class="modal fade" tabindex="-1" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-card border-0 text-white shadow-lg" style="box-shadow: 0 0 50px rgba(0,0,0,0.5) !important;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">
                        <i class="bi bi-gift-fill me-2 text-warning"></i>新年祝福
                        <a href="vendor/public/newfireworks/index.html" target="_blank" class="ms-3 text-white text-decoration-none" style="font-size: 0.8rem; opacity: 0.8;">外面的世界这么喧嚣，来看看烟花吧</a>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <?php if (!empty($config['newyear_video'])): ?>
                    <div class="mb-3 position-relative" style="min-height: 200px;">
                        <!-- 加载动画 -->
                        <div id="videoLoadingSpinner" class="position-absolute top-50 start-50 translate-middle text-center" style="z-index: 10;">
                            <div class="spinner-border text-light" role="status" style="width: 3rem; height: 3rem;">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-white small">正在加载新春祝福...</p>
                        </div>
                        
                        <!-- 视频元素 (初始隐藏) -->
                        <video id="newyearVideo" 
                               src="<?= e($config['newyear_video']) ?>" 
                               class="w-100 rounded" 
                               style="max-height: 300px; opacity: 0; transition: opacity 0.5s ease;" 
                               controls 
                               muted 
                               controlsList="nodownload" 
                               oncontextmenu="return false;" 
                               preload="auto"></video>
                    </div>
                    <?php endif; ?>
                    <div class="mb-4">
                        <p class="fs-5"><?= e($config['newyear_message'] ?? '祝大家新年快乐，万事如意！') ?></p>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-danger rounded-pill px-4" data-bs-dismiss="modal" onclick="markNewYearVideoWatched()">
                            <i class="bi bi-check-lg me-1"></i>收下祝福
                        </button>
                        <a href="/vendor/guestbook.php" class="btn rounded-pill px-4 position-relative" style="background: linear-gradient(135deg, #87CEEB, #98FB98); border: none; color: white;" title="快去填写你的新春祝福吧" onclick="markNewYearVideoWatched()">
                            <i class="bi bi-chat-heart-fill me-1"></i>填写你的新春祝福吧
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning">
                                <i class="bi bi-gift-fill"></i>
                                <span class="visually-hidden">祝福</span>
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // 标记新春祝福视频已观看 (当前已禁用记录功能，每次刷新都会显示)
    function markNewYearVideoWatched() {
        const videoId = '<?= md5($config['newyear_video'] ?? 'default') ?>';
        // localStorage.setItem('newyear_video_watched_' + videoId, 'true'); // 已禁用
        
        const video = document.getElementById('newyearVideo');
        if (video) {
            video.pause();
        }
        
        // 手动关闭模态框，确保遮罩层移除
        const modalEl = document.getElementById('newyearModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) {
            modal.hide();
        }
        
        // 强制移除遮罩层和body类（双重保险）
        setTimeout(() => {
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }, 300);
    }

    // 监听模态框关闭事件，确保视频暂停
    const newyearModalEl = document.getElementById('newyearModal');
    if (newyearModalEl) {
        newyearModalEl.addEventListener('hidden.bs.modal', function () {
            const video = document.getElementById('newyearVideo');
            if (video) {
                video.pause();
            }
            // 恢复页面滚动
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    }

    // 检查是否需要显示新春祝福弹窗
    document.addEventListener('DOMContentLoaded', function() {
        // 先检查是否有视频 URL
        const videoUrl = '<?= e($config['newyear_video'] ?? '') ?>';
        const videoId = '<?= md5($config['newyear_video'] ?? 'default') ?>';
        // const hasWatched = localStorage.getItem('newyear_video_watched_' + videoId); // 已禁用
        const hasWatched = null; // 强制设为未观看，每次都显示
        const newyearEnable = '<?= $config['newyear_enable'] ?? '' ?>';
        
        // 获取开始和结束时间
        const startTime = '<?php echo !empty($config['newyear_start_time']) ? $config['newyear_start_time'] : ''; ?>';
        const endTime = '<?php echo !empty($config['newyear_end_time']) ? $config['newyear_end_time'] : ''; ?>';
        
        // 调试模式：如果 URL 包含 debug_newyear=1，则强制显示
        const urlParams = new URLSearchParams(window.location.search);
        const forceDebug = urlParams.get('debug_newyear') === '1';

        console.log('🧧 新春祝福调试:', {
            '是否启用': newyearEnable ? '是' : '否',
            '视频地址': videoUrl ? videoUrl : '未设置',
            '观看状态': '强制未观看 (测试模式)',
            '强制调试模式': forceDebug ? '开启' : '关闭',
            '弹窗元素': document.getElementById('newyearModal') ? '存在' : '不存在',
            '开始时间': startTime || '未设置',
            '结束时间': endTime || '未设置'
        });
        
        // 检查当前时间是否在指定范围内
        function shouldShowModal() {
            const now = new Date();
            
            // 如果没有设置开始时间，默认立即显示
            if (!startTime) {
                // 如果没有设置结束时间，一直显示
                if (!endTime) {
                    return true;
                }
                // 检查是否在结束时间之前
                const endDate = new Date(endTime);
                return now <= endDate;
            }
            
            // 检查是否在开始时间之后
            const startDate = new Date(startTime);
            if (now < startDate) {
                console.log('🧧 当前时间未到开始时间，不显示弹窗');
                return false;
            }
            
            // 如果没有设置结束时间，只要过了开始时间就显示
            if (!endTime) {
                return true;
            }
            
            // 检查是否在结束时间之前
            const endDate = new Date(endTime);
            return now <= endDate;
        }
        
        // 调试模式下强制显示
        if (forceDebug) {
            console.log('🧧 调试模式：强制显示弹窗');
        }
        
        // 只有当启用了新年祝福、未观看过、且在时间范围内时才显示（或者在调试模式下）
        if (document.getElementById('newyearModal') && newyearEnable && (forceDebug || shouldShowModal())) {
            const newyearModal = new bootstrap.Modal(document.getElementById('newyearModal'));
            const video = document.getElementById('newyearVideo');
            const spinner = document.getElementById('videoLoadingSpinner');
            
            if (video && videoUrl) {
                // 判断是否为移动端
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                
                if (isMobile) {
                    // 移动端：直接从服务器加载，不使用缓存
                    console.log('📱 移动端检测: 直接从服务器加载视频');
                    video.src = videoUrl;
                    
                    // 显示模态框
                    newyearModal.show();
                    
                    // 隐藏 spinner，显示视频
                    if (spinner) spinner.style.display = 'none';
                    video.style.opacity = '1';
                    video.play()
                        .then(() => console.log('▶️ 移动端开始播放'))
                        .catch(e => console.warn('⚠️ 自动播放被阻止:', e));
                } else {
                    // PC端：尝试使用 Cache API 缓存视频
                    // 0. 请求持久化存储权限
                    if (navigator.storage && navigator.storage.persist) {
                        navigator.storage.persist().then(persistent => {
                            console.log(persistent ? '📦 存储状态: 除非用户手动清除，否则数据将永久保存' : '📦 存储状态: 可能会在存储空间不足时被浏览器清理');
                        });
                    }

                    // 1. 尝试从 Cache API 获取视频
                    console.group('🎉 新春祝福视频加载器');
                    console.log('正在检查缓存:', videoUrl);
                    
                    caches.match(videoUrl).then(response => {
                        if (response) {
                            // 命中缓存，直接使用 Blob URL
                            console.log('✅ 命中缓存: 在 Cache API 中找到视频');
                            response.blob().then(blob => {
                                const cachedUrl = URL.createObjectURL(blob);
                                console.log('📦 从缓存创建 Blob:', (blob.size / 1024 / 1024).toFixed(2), 'MB');
                                console.log('🔗 生成本地 URL:', cachedUrl);
                                
                                video.src = cachedUrl;
                                
                                // 显示模态框
                                newyearModal.show();
                                
                                // 隐藏 spinner，显示视频
                                if (spinner) spinner.style.display = 'none';
                                video.style.opacity = '1';
                                video.play()
                                    .then(() => console.log('▶️ 开始播放缓存视频'))
                                    .catch(e => console.warn('⚠️ 自动播放被阻止:', e));
                                console.groupEnd();
                            });
                        } else {
                            // 2. 未命中缓存，开始下载并缓存
                            console.log('❌ 未命中缓存: 开始下载视频...');
                            console.time('⬇️ 下载耗时');
                            
                            // 下载时也先显示模态框和加载动画，让用户知道在加载
                            newyearModal.show();
                            
                            fetch(videoUrl)
                                .then(networkResponse => {
                                    if (!networkResponse.ok) throw new Error('网络响应异常: ' + networkResponse.statusText);
                                    
                                    console.log('✅ 接收到网络响应');
                                    // 复制响应用于缓存
                                    const responseToCache = networkResponse.clone();
                                    
                                    // 打开缓存并存储（Cache API 是设计用于存储视频等大文件的最佳选择）
                                    caches.open('newyear-video-cache-v1').then(cache => {
                                        cache.put(videoUrl, responseToCache);
                                        console.log('💾 视频已缓存到 "newyear-video-cache-v1" (永久存储)');
                                    });
                                    
                                    // 获取 Blob 并播放
                                    return networkResponse.blob();
                                })
                                .then(blob => {
                                    console.timeEnd('⬇️ 下载耗时');
                                    console.log('📦 下载完成，文件大小:', (blob.size / 1024 / 1024).toFixed(2), 'MB');
                                    const localUrl = URL.createObjectURL(blob);
                                    console.log('🔗 生成本地 URL:', localUrl);
                                    
                                    video.src = localUrl;
                                    
                                    // 隐藏 spinner，显示视频
                                    if (spinner) spinner.style.display = 'none';
                                    video.style.opacity = '1';
                                    video.play()
                                        .then(() => console.log('▶️ 开始播放网络下载视频'))
                                        .catch(e => console.warn('⚠️ 自动播放被阻止:', e));
                                    console.groupEnd();
                                })
                                .catch(err => {
                                    console.error('❌ 获取/缓存失败:', err);
                                    console.timeEnd('⬇️ 下载耗时');
                                    // 降级：直接使用原始 URL，不等待完全下载
                                    console.log('⚠️ 降级处理: 直接使用原始 URL');
                                    video.src = videoUrl;
                                    if (spinner) spinner.style.display = 'none';
                                    video.style.opacity = '1';
                                    video.play().catch(e => console.warn('⚠️ 自动播放被阻止:', e));
                                    console.groupEnd();
                                });
                        }
                    });
                }
            } else {
                // 没有视频，直接显示模态框
                newyearModal.show();
            }
        }
    });
    </script>
    <?php endif; ?>

    <!-- 页脚样式 -->
    <style>
        footer {
            background-color: #ffffff !important;
            border-top: 1px solid #dee2e6;
            padding: 1rem 0;
            font-size: 0.9rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        footer .footer-links a {
            color: #6c757d;
            text-decoration: none;
            transition: color 0.2s;
        }
        footer .footer-links a i {
            color: #495057;
        }
        footer .footer-links a:hover {
            color: #007bff;
        }
        footer .footer-links a:hover i {
            color: #007bff;
        }
        footer .footer-info {
            color: #495057;
        }

        /* 深色模式页脚适配 */
        [data-bs-theme="dark"] footer {
            background-color: #212529 !important;
            border-top: 1px solid #373b3e;
            color: #adb5bd;
        }
        [data-bs-theme="dark"] footer .footer-links a {
            color: #adb5bd;
        }
        [data-bs-theme="dark"] footer .footer-links a i {
            color: #adb5bd;
        }
        [data-bs-theme="dark"] footer .footer-links a:hover {
            color: #fff;
        }
        [data-bs-theme="dark"] footer .footer-links a:hover i {
            color: #fff;
        }
        [data-bs-theme="dark"] footer .footer-info {
            color: #adb5bd;
        }
    </style>

    <!-- 页脚 -->
    <?php require_once 'vendor/footer.php'; ?>

    <!-- QQ Modal -->
    <div class="modal fade" id="qqModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass-card border-0">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center pt-0 pb-4">
                    <div class="mb-3 text-primary">
                        <i class="fa-brands fa-qq fa-3x"></i>
                    </div>
                    <h5 class="mb-2 fw-bold">添加好友</h5>
                    <p class="mb-3 text-muted user-select-all fs-5" id="qq-content"><?= e($config['contact_qq'] ?? '') ?></p>
                    <div class="d-flex flex-column gap-2 align-items-center justify-content-center">
                        <button class="btn btn-sm btn-outline-primary rounded-pill px-4 w-75" onclick="copyContent('qq-content', this)">
                            <i class="bi bi-clipboard me-1"></i>复制QQ号
                        </button>
                        <a href="tencent://message/?uin=<?= e($config['contact_qq'] ?? '') ?>&Site=<?= urlencode($config['website_name']) ?>&Menu=yes" class="btn btn-sm btn-primary rounded-pill px-4 w-75">
                            <i class="bi bi-chat-dots me-1"></i>打开QQ
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- WeChat Modal -->
    <div class="modal fade" id="wechatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass-card border-0">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center pt-0 pb-4">
                    <div class="mb-3 text-success">
                        <i class="fa-brands fa-weixin fa-3x"></i>
                    </div>
                    <h5 class="mb-2 fw-bold">添加微信</h5>
                    <p class="mb-3 text-muted user-select-all fs-5" id="wechat-content"><?= e($config['social_wechat'] ?? '') ?></p>
                    <div class="d-flex flex-column gap-2 align-items-center justify-content-center">
                        <button class="btn btn-sm btn-outline-success rounded-pill px-4 w-75" onclick="copyContent('wechat-content', this)">
                            <i class="bi bi-clipboard me-1"></i>复制微信号
                        </button>
                        <a href="weixin://" class="btn btn-sm btn-success rounded-pill px-4 w-75">
                            <i class="bi bi-chat-dots me-1"></i>打开微信
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php renderMusicPlayer($config); ?>
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= getResourceUrl('/assets/js/marked.min.js', 'https://cdn.staticfile.net/marked/11.1.1/marked.min.js') ?>"></script>
    
    <!-- Sphere & Clock Scripts — defer 延迟加载，不阻塞首屏渲染 -->
    <script defer src="/assets/js/sphere/threejs-6ebcc050.js"></script>
    <script defer src="/assets/js/sphere/projector-2f448d36.js"></script>
    <script defer src="/assets/js/sphere/canvasrenderer-0792e9f3.js"></script>
    <script>
    // Sphere Animation — 等待 defer 依赖（Three.js 等）加载完成
    window.addEventListener('DOMContentLoaded', function() {
    (function() {
      "use strict";
      var camera, scene, renderer;
      var container = document.getElementById("sphere-container");
      if (!container) return;

      var width = container.clientWidth;
      var height = container.clientHeight;
      var mouseX = 0, mouseY = 0;
      var windowHalfX = width / 2;
      var windowHalfY = height / 2;

      function init() {
        // 使用父容器的高度
        width = container.clientWidth;
        height = container.clientHeight || 500;
        
        // 使用较小的 FOV (30度) 来减少透视畸变
        camera = new THREE.PerspectiveCamera(30, width / height, 1, 10000);
        
        // 初始设置相机距离
        updateCameraZ();

        scene = new THREE.Scene();

        renderer = new THREE.CanvasRenderer({ alpha: true });
        renderer.setPixelRatio(window.devicePixelRatio);
        renderer.setSize(width, height);
        container.appendChild(renderer.domElement);

        var PI2 = Math.PI * 2;
        var material = new THREE.SpriteCanvasMaterial({
          color: 0xffffff,
          program: function(context) {
            context.beginPath();
            context.arc(0, 0, 0.5, 0, PI2, true);
            context.fill();
          }
        });

        // 增加粒子数量以保持密度
        for (var i = 0; i < 1200; i++) {
          var particle = new THREE.Sprite(material);
          particle.position.x = Math.random() * 2 - 1;
          particle.position.y = Math.random() * 2 - 1;
          particle.position.z = Math.random() * 2 - 1;
          particle.position.normalize();
          // 调整球体物理半径 (450)
          particle.position.multiplyScalar(Math.random() * 10 + 450);
          
          // 增大粒子大小
          particle.scale.multiplyScalar(Math.random() * 4 + 2);
          scene.add(particle);
        }

        document.addEventListener("mousemove", onDocumentMouseMove, false);
        window.addEventListener("resize", onWindowResize, false);
      }

      function updateCameraZ() {
        var aspect = width / height;
        // 动态调整相机距离实现自适应缩放
        // 基础距离 1800 适合宽高比 >= 1 的情况
        // 当宽度变小 (aspect < 1) 时，拉远相机以保持球体完整可见
        camera.position.z = aspect >= 1 ? 1800 : 1800 / aspect;
      }

      function onWindowResize() {
        if (!container) return;
        width = container.clientWidth;
        height = container.clientHeight;
        windowHalfX = width / 2;
        windowHalfY = height / 2;
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        renderer.setSize(width, height);
        updateCameraZ(); // 窗口大小改变时更新相机距离
      }

      function onDocumentMouseMove(event) {
        mouseX = event.clientX - window.innerWidth / 2;
        mouseY = event.clientY - window.innerHeight / 2;
      }

      function animate() {
        requestAnimationFrame(animate);
        render();
      }

      function render() {
        camera.position.x += (mouseX - camera.position.x) * 0.05;
        camera.position.y += (-mouseY + 200 - camera.position.y) * 0.05;
        camera.lookAt(scene.position);
        renderer.setClearColor(0x000000, 0);
        renderer.render(scene, camera);
      }

      init();
      animate();
    })();

    // Clock Animation
    (function() {
        var canvas = document.getElementById("clock-canvas");
        if (!canvas) return;
        
        var context = canvas.getContext("2d");
        var WINDOW_WIDTH = 350;
        var WINDOW_HEIGHT = 100;
        var RADIUS = 2;
        var MARGIN_TOP = 10;
        var MARGIN_LEFT = 10;
        var curShowTimeSeconds = 0;
        var balls = [];
        const colors = ["#33B5E5", "#0099CC", "#AA66CC", "#9933CC", "#99CC00", "#669900", "#FFBB33", "#FF8800", "#FF4444", "#CC0000"];
        
        // Digit Matrix (0-9 and :)
        var digit = [
            [[0,0,1,1,1,0,0],[0,1,1,0,1,1,0],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,0,1,1,0],[0,0,1,1,1,0,0]],
            [[0,0,0,1,1,0,0],[0,1,1,1,1,0,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[1,1,1,1,1,1,1]],
            [[0,1,1,1,1,1,0],[1,1,0,0,0,1,1],[0,0,0,0,0,1,1],[0,0,0,0,1,1,0],[0,0,0,1,1,0,0],[0,0,1,1,0,0,0],[0,1,1,0,0,0,0],[1,1,0,0,0,0,0],[1,1,0,0,0,1,1],[1,1,1,1,1,1,1]],
            [[1,1,1,1,1,1,1],[0,0,0,0,0,1,1],[0,0,0,0,0,1,1],[0,0,0,0,1,1,0],[0,0,0,1,1,0,0],[0,0,0,0,1,1,0],[0,0,0,0,0,1,1],[0,0,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,1,1,1,0]],
            [[0,0,0,0,1,1,0],[0,0,0,1,1,1,0],[0,0,1,1,1,1,0],[0,1,1,0,1,1,0],[1,1,0,0,1,1,0],[1,1,1,1,1,1,1],[0,0,0,0,1,1,0],[0,0,0,0,1,1,0],[0,0,0,0,1,1,0],[0,0,0,1,1,1,1]],
            [[1,1,1,1,1,1,1],[1,1,0,0,0,0,0],[1,1,0,0,0,0,0],[1,1,1,1,1,1,0],[0,0,0,0,0,1,1],[0,0,0,0,0,1,1],[0,0,0,0,0,1,1],[0,0,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,1,1,1,0]],
            [[0,0,1,1,1,1,0],[0,1,1,0,0,0,0],[1,1,0,0,0,0,0],[1,1,0,0,0,0,0],[1,1,1,1,1,1,0],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,1,1,1,0]],
            [[1,1,1,1,1,1,1],[1,1,0,0,0,1,1],[0,0,0,0,1,1,0],[0,0,0,0,1,1,0],[0,0,0,1,1,0,0],[0,0,0,1,1,0,0],[0,0,1,1,0,0,0],[0,0,1,1,0,0,0],[0,0,1,1,0,0,0],[0,0,1,1,0,0,0]],
            [[0,1,1,1,1,1,0],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,1,1,1,0],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,1,1,1,0]],
            [[0,1,1,1,1,1,0],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[1,1,0,0,0,1,1],[0,1,1,1,1,1,1],[0,0,0,0,0,1,1],[0,0,0,0,0,1,1],[0,0,0,0,1,1,0],[0,0,0,1,1,0,0],[0,1,1,1,0,0,0]],
            [[0,0,0,0],[0,0,0,0],[0,1,1,0],[0,1,1,0],[0,0,0,0],[0,0,0,0],[0,1,1,0],[0,1,1,0],[0,0,0,0],[0,0,0,0]]
        ];

        // Init
        var dpr = window.devicePixelRatio || 1;
        canvas.width = WINDOW_WIDTH * dpr;
        canvas.height = WINDOW_HEIGHT * dpr;
        context.scale(dpr, dpr);
        
        curShowTimeSeconds = getCurrentShowTimeSeconds();
        
        setInterval(function() {
            render(context);
            update();
        }, 50);

        function getCurrentShowTimeSeconds() {
            var curTime = new Date();
            var ret = curTime.getHours() * 3600 + curTime.getMinutes() * 60 + curTime.getSeconds();
            return ret;
        }

        function update() {
            var nextShowTimeSeconds = getCurrentShowTimeSeconds();
            var nextHours = parseInt(nextShowTimeSeconds / 3600);
            var nextMinutes = parseInt((nextShowTimeSeconds - nextHours * 3600) / 60);
            var nextSeconds = nextShowTimeSeconds % 60;

            var curHours = parseInt(curShowTimeSeconds / 3600);
            var curMinutes = parseInt((curShowTimeSeconds - curHours * 3600) / 60);
            var curSeconds = curShowTimeSeconds % 60;

            if (nextSeconds != curSeconds) {
                if (parseInt(curHours / 10) != parseInt(nextHours / 10)) addBalls(MARGIN_LEFT + 0, MARGIN_TOP, parseInt(curHours / 10));
                if (parseInt(curHours % 10) != parseInt(nextHours % 10)) addBalls(MARGIN_LEFT + 15 * (RADIUS + 1), MARGIN_TOP, parseInt(curHours % 10));
                if (parseInt(curMinutes / 10) != parseInt(nextMinutes / 10)) addBalls(MARGIN_LEFT + 39 * (RADIUS + 1), MARGIN_TOP, parseInt(curMinutes / 10));
                if (parseInt(curMinutes % 10) != parseInt(nextMinutes % 10)) addBalls(MARGIN_LEFT + 54 * (RADIUS + 1), MARGIN_TOP, parseInt(curMinutes % 10));
                if (parseInt(curSeconds / 10) != parseInt(nextSeconds / 10)) addBalls(MARGIN_LEFT + 78 * (RADIUS + 1), MARGIN_TOP, parseInt(curSeconds / 10));
                if (parseInt(curSeconds % 10) != parseInt(nextSeconds % 10)) addBalls(MARGIN_LEFT + 93 * (RADIUS + 1), MARGIN_TOP, parseInt(curSeconds % 10));
                curShowTimeSeconds = nextShowTimeSeconds;
            }
            updateBalls();
        }

        function updateBalls() {
            for (var i = 0; i < balls.length; i++) {
                balls[i].x += balls[i].vx;
                balls[i].y += balls[i].vy;
                balls[i].vy += balls[i].g;
                if (balls[i].y >= WINDOW_HEIGHT - RADIUS) {
                    balls[i].y = WINDOW_HEIGHT - RADIUS;
                    balls[i].vy = -balls[i].vy * 0.55;
                }
            }
            
            var cnt = 0;
            for (var i = 0; i < balls.length; i++) {
                if (balls[i].x + RADIUS > 0 && balls[i].x - RADIUS < WINDOW_WIDTH) {
                    balls[cnt++] = balls[i];
                }
            }
            while (balls.length > Math.min(300, cnt)) balls.pop();
        }

        function addBalls(x, y, num) {
            for (var i = 0; i < digit[num].length; i++) {
                for (var j = 0; j < digit[num][i].length; j++) {
                    if (digit[num][i][j] == 1) {
                        var aBall = {
                            x: x + j * 2 * (RADIUS + 1) + (RADIUS + 1),
                            y: y + i * 2 * (RADIUS + 1) + (RADIUS + 1),
                            g: 1.5 + Math.random(),
                            vx: Math.pow(-1, Math.ceil(Math.random() * 1000)) * 4,
                            vy: -5,
                            color: colors[Math.floor(Math.random() * colors.length)]
                        };
                        balls.push(aBall);
                    }
                }
            }
        }

        function render(cxt) {
            cxt.clearRect(0, 0, WINDOW_WIDTH, WINDOW_HEIGHT);
            var hours = parseInt(curShowTimeSeconds / 3600);
            var minutes = parseInt((curShowTimeSeconds - hours * 3600) / 60);
            var seconds = curShowTimeSeconds % 60;

            renderDigit(MARGIN_LEFT, MARGIN_TOP, parseInt(hours / 10), cxt);
            renderDigit(MARGIN_LEFT + 15 * (RADIUS + 1), MARGIN_TOP, parseInt(hours % 10), cxt);
            renderDigit(MARGIN_LEFT + 30 * (RADIUS + 1), MARGIN_TOP, 10, cxt);
            renderDigit(MARGIN_LEFT + 39 * (RADIUS + 1), MARGIN_TOP, parseInt(minutes / 10), cxt);
            renderDigit(MARGIN_LEFT + 54 * (RADIUS + 1), MARGIN_TOP, parseInt(minutes % 10), cxt);
            renderDigit(MARGIN_LEFT + 69 * (RADIUS + 1), MARGIN_TOP, 10, cxt);
            renderDigit(MARGIN_LEFT + 78 * (RADIUS + 1), MARGIN_TOP, parseInt(seconds / 10), cxt);
            renderDigit(MARGIN_LEFT + 93 * (RADIUS + 1), MARGIN_TOP, parseInt(seconds % 10), cxt);

            for (var i = 0; i < balls.length; i++) {
                cxt.fillStyle = balls[i].color;
                cxt.beginPath();
                cxt.arc(balls[i].x, balls[i].y, RADIUS, 0, 2 * Math.PI);
                cxt.closePath();
                cxt.fill();
            }
        }

        function renderDigit(x, y, num, cxt) {
            cxt.fillStyle = "rgb(255, 255, 255)";
            for (var i = 0; i < digit[num].length; i++) {
                for (var j = 0; j < digit[num][i].length; j++) {
                    if (digit[num][i][j] == 1) {
                        cxt.beginPath();
                        cxt.arc(x + j * 2 * (RADIUS + 1) + (RADIUS + 1), y + i * 2 * (RADIUS + 1) + (RADIUS + 1), RADIUS, 0, 2 * Math.PI);
                        cxt.closePath();
                        cxt.fill();
                    }
                }
            }
        }
    })();
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 尝试自动播放背景视频
        const bgVideo = document.getElementById('bgVideo');
        if (bgVideo) {
            bgVideo.muted = true; // 确保静音
            bgVideo.play().catch(function(error) {
                console.log("Video autoplay failed:", error);
                // 如果自动播放失败，可以在这里处理，例如显示播放按钮（对于背景视频通常不需要）
            });
        }

        // 使用 marked.js 渲染 Markdown 内容
        // 渲染新的 About Me Section 内容
        const aboutMeDetail = document.getElementById('about-me-detail');
        const aboutMeMarkdown = document.getElementById('about-me-markdown');
        if (aboutMeDetail && aboutMeMarkdown) {
             const mdText = aboutMeMarkdown.textContent;
             if (mdText && typeof marked !== 'undefined') {
                 // 配置 marked.js (只需配置一次)
                 marked.setOptions({
                    breaks: true,
                    gfm: true
                 });
                 aboutMeDetail.innerHTML = marked.parse(mdText);
             }
        }

        const aboutContent = document.getElementById('about-content');
        const markdownData = document.getElementById('about-markdown-data');
        
        if (aboutContent && markdownData) {
            const markdownText = markdownData.textContent;
            if (markdownText) {
                // 配置 marked.js
                marked.setOptions({
                    breaks: true, // 支持 GitHub 风格的换行
                    gfm: true     // 启用 GitHub 风格 Markdown
                });
                aboutContent.innerHTML = marked.parse(markdownText);
                
                // 填充模态框内容
                const aboutModalContent = document.getElementById('about-modal-content');
                if (aboutModalContent) {
                    aboutModalContent.innerHTML = aboutContent.innerHTML;
                }
                
                // 检测是否溢出
                setTimeout(() => {
                    if (aboutContent.scrollHeight > aboutContent.clientHeight) {
                        const moreBtn = document.querySelector('.about-more-btn');
                        if (moreBtn) {
                            moreBtn.style.display = 'inline-block';
                        }
                    }
                }, 100);
            }
        } else if (aboutContent) {
            // 兼容旧的 data-markdown 方式
            const markdownText = aboutContent.getAttribute('data-markdown');
            if (markdownText) {
                aboutContent.innerHTML = marked.parse(markdownText);
                
                // 填充模态框内容
                const aboutModalContent = document.getElementById('about-modal-content');
                if (aboutModalContent) {
                    aboutModalContent.innerHTML = aboutContent.innerHTML;
                }
                
                // 检测是否溢出
                setTimeout(() => {
                    if (aboutContent.scrollHeight > aboutContent.clientHeight) {
                        const moreBtn = document.querySelector('.about-more-btn');
                        if (moreBtn) {
                            moreBtn.style.display = 'inline-block';
                        }
                    }
                }, 100);
            }
        }
    });
        
        // 显示 About 模态框
        function showAboutModal() {
            document.getElementById('aboutModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // 关闭 About 模态框
        function closeAboutModal(event) {
            if (event) event.stopPropagation();
            document.getElementById('aboutModal').classList.remove('show');
            document.body.style.overflow = '';
        }
    </script>
    <script>
        // 视频缓存管理器
        class VideoCacheManager {
            constructor() {
                this.cacheName = 'skytech-video-cache';
                this.maxCacheAge = 24 * 60 * 60 * 1000; // 24小时
                this.initCache();
            }

            initCache() {
                // 检查是否支持localStorage
                if (typeof Storage === 'undefined') {
                    console.log('浏览器不支持localStorage，跳过视频缓存');
                    return;
                }
            }

            // 获取缓存的视频信息
            getCachedVideo(videoUrl) {
                if (!videoUrl) return null;
                
                try {
                    const cacheData = localStorage.getItem(this.cacheName);
                    if (!cacheData) return null;

                    const cache = JSON.parse(cacheData);
                    const cachedVideo = cache[videoUrl];
                    
                    if (!cachedVideo) return null;

                    // 检查缓存是否过期
                    const now = Date.now();
                    if (now - cachedVideo.timestamp > this.maxCacheAge) {
                        this.removeCachedVideo(videoUrl);
                        return null;
                    }

                    console.log('找到缓存的视频:', videoUrl);
                    return cachedVideo;
                } catch (error) {
                    console.error('读取视频缓存失败:', error);
                    return null;
                }
            }

            // 缓存视频信息
            cacheVideo(videoUrl, videoElement) {
                if (!videoUrl || !videoElement) return;

                try {
                    const cacheData = localStorage.getItem(this.cacheName);
                    const cache = cacheData ? JSON.parse(cacheData) : {};

                    // 清理过期缓存
                    this.cleanExpiredCache(cache);

                    // 添加新的缓存项
                    cache[videoUrl] = {
                        timestamp: Date.now(),
                        duration: videoElement.duration || 0,
                        size: this.estimateVideoSize(videoElement),
                        cached: true
                    };

                    localStorage.setItem(this.cacheName, JSON.stringify(cache));
                    console.log('视频已缓存:', videoUrl);
                } catch (error) {
                    console.error('缓存视频失败:', error);
                    // 如果localStorage满了，尝试清理一些旧缓存
                    if (error.name === 'QuotaExceededError') {
                        this.clearOldCache();
                        this.cacheVideo(videoUrl, videoElement);
                    }
                }
            }

            // 移除特定视频缓存
            removeCachedVideo(videoUrl) {
                try {
                    const cacheData = localStorage.getItem(this.cacheName);
                    if (!cacheData) return;

                    const cache = JSON.parse(cacheData);
                    delete cache[videoUrl];
                    localStorage.setItem(this.cacheName, JSON.stringify(cache));
                    console.log('移除视频缓存:', videoUrl);
                } catch (error) {
                    console.error('移除视频缓存失败:', error);
                }
            }

            // 清理过期缓存
            cleanExpiredCache(cache) {
                const now = Date.now();
                const urls = Object.keys(cache);
                
                urls.forEach(url => {
                    if (now - cache[url].timestamp > this.maxCacheAge) {
                        delete cache[url];
                    }
                });
            }

            // 清理最旧的缓存
            clearOldCache() {
                try {
                    const cacheData = localStorage.getItem(this.cacheName);
                    if (!cacheData) return;

                    const cache = JSON.parse(cacheData);
                    const urls = Object.keys(cache);
                    
                    if (urls.length > 0) {
                        // 找到最旧的缓存项
                        let oldestUrl = urls[0];
                        let oldestTime = cache[oldestUrl].timestamp;
                        
                        urls.forEach(url => {
                            if (cache[url].timestamp < oldestTime) {
                                oldestTime = cache[url].timestamp;
                                oldestUrl = url;
                            }
                        });
                        
                        delete cache[oldestUrl];
                        localStorage.setItem(this.cacheName, JSON.stringify(cache));
                        console.log('清理最旧的缓存:', oldestUrl);
                    }
                } catch (error) {
                    console.error('清理缓存失败:', error);
                }
            }

            // 估算视频大小（粗糙估算）
            estimateVideoSize(videoElement) {
                if (!videoElement || !videoElement.duration) return 0;
                
                // 假设平均比特率为1Mbps，计算大小（字节）
                const bitrate = 1000000; // 1Mbps
                return Math.floor(videoElement.duration * bitrate / 8);
            }

            // 检查视频是否需要重新下载
            shouldRedownloadVideo(videoUrl) {
                const cachedVideo = this.getCachedVideo(videoUrl);
                return !cachedVideo;
            }

            // 获取所有缓存信息（用于调试）
            getCacheInfo() {
                try {
                    const cacheData = localStorage.getItem(this.cacheName);
                    if (!cacheData) return {};

                    const cache = JSON.parse(cacheData);
                    const info = {};
                    
                    Object.keys(cache).forEach(url => {
                        const video = cache[url];
                        info[url] = {
                            cached: video.cached,
                            timestamp: new Date(video.timestamp).toLocaleString(),
                            size: this.formatFileSize(video.size),
                            age: Math.floor((Date.now() - video.timestamp) / 1000 / 60) // 分钟
                        };
                    });
                    
                    return info;
                } catch (error) {
                    console.error('获取缓存信息失败:', error);
                    return {};
                }
            }

            // 格式化文件大小
            formatFileSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
        }

        // 图片切换管理器
        class BackgroundImageManager {
            constructor() {
                this.bgImage = document.getElementById('bgImage');
                this.bgImageTransition = document.getElementById('bgImageTransition'); // 获取过渡层
                this.bgVideo = document.getElementById('bgVideo');
                this.bgLoading = document.getElementById('bgLoading');
                this.customImage = this.bgImage.getAttribute('data-custom-image');
                this.useBing = this.bgImage.getAttribute('data-use-bing') === '1';
                this.bingApi = this.bgImage.getAttribute('data-bing-api');
                this.isMobile = this.bgImage.getAttribute('data-is-mobile') === '1';
                this.imageUrls = [];
                this.currentImageIndex = 0;
                this.intervalTime = 10000; // 10秒
                this.timer = null;
                this.videoEnded = false;
                this.videoFailed = false;
                this.retryCount = 0;
                this.maxRetries = 3;
                this.currentVideoUrl = null;
                
                // 初始化视频缓存管理器
                this.videoCache = new VideoCacheManager();
                
                // API列表 - 使用后台配置的API
                this.apiList = {
                    mobile: [],
                    desktop: []
                };

                if (this.bingApi) {
                    this.apiList.mobile.push(this.bingApi);
                    this.apiList.desktop.push(this.bingApi);
                } else {
                    // 如果没有配置API，使用默认的官方Bing每日图片API
                    const defaultApi = 'https://bing.img.run/rand.php'; 
                    this.apiList.mobile.push(defaultApi);
                    this.apiList.desktop.push(defaultApi);
                }
                
                // 当前使用的API索引
                this.currentApiIndex = {
                    mobile: 0,
                    desktop: 0
                };
                
                // 初始化管理器
                this.init();
            }
            
            init() {
                console.log('背景管理器初始化');
                console.log('自定义图片:', this.customImage ? '有' : '无');
                console.log('使用Bing背景:', this.useBing);
                console.log('移动设备:', this.isMobile);
                
                // 如果有视频，首先尝试播放视频
                if (this.bgVideo) {
                    console.log('检测到背景视频，开始播放');
                    this.initVideo();
                } else {
                    console.log('没有背景视频，直接处理图片背景');
                    // 没有视频，直接进入图片处理流程
                    this.handleImageBackground();
                }
            }
            
            initVideo() {
                this.bgVideo.muted = true;
                // 移除loop属性，确保视频只播放一次
                this.bgVideo.removeAttribute('loop');
                
                // 获取当前视频URL
                const videoSrc = this.bgVideo.querySelector('source')?.src || this.bgVideo.src;
                this.currentVideoUrl = videoSrc;
                
                console.log('当前视频URL:', this.currentVideoUrl);
                
                // 检查是否需要重新下载视频
                if (!this.videoCache.shouldRedownloadVideo(this.currentVideoUrl)) {
                    console.log('视频已缓存，跳过重复下载');
                    // 直接设置播放，不需要重新加载
                    this.setupVideoEvents();
                    this.playVideo();
                    return;
                }
                
                // 需要重新下载视频
                console.log('视频需要重新下载或首次加载');
                this.setupVideoEvents();
                this.loadVideo();
            }

            setupVideoEvents() {
                // 监听视频事件
                this.bgVideo.addEventListener('ended', () => {
                    console.log('视频播放结束');
                    this.videoEnded = true;
                    this.onVideoComplete();
                });
                
                this.bgVideo.addEventListener('error', (error) => {
                    console.log('视频加载/播放失败:', error);
                    this.videoFailed = true;
                    this.bgVideo.style.display = 'none';
                    this.handleImageBackground();
                });
                
                this.bgVideo.addEventListener('loadeddata', () => {
                    console.log('视频数据已加载');
                    // 缓存视频信息
                    if (this.currentVideoUrl) {
                        this.videoCache.cacheVideo(this.currentVideoUrl, this.bgVideo);
                    }
                });
                
                this.bgVideo.addEventListener('canplay', () => {
                    console.log('视频可以播放');
                });
                
                this.bgVideo.addEventListener('loadstart', () => {
                    console.log('开始加载视频');
                });
            }

            loadVideo() {
                this.showLoading();
                
                // 为视频添加时间戳以避免缓存
                const videoSource = this.bgVideo.querySelector('source');
                if (videoSource && this.currentVideoUrl) {
                    const separator = this.currentVideoUrl.includes('?') ? '&' : '?';
                    videoSource.src = this.currentVideoUrl + separator + 't=' + Date.now();
                    this.bgVideo.load();
                }
                
                this.playVideo();
            }

            playVideo() {
                // 尝试播放视频
                const playPromise = this.bgVideo.play();
                
                if (playPromise !== undefined) {
                    playPromise.then(() => {
                        console.log('视频自动播放成功');
                        this.hideLoading();
                    }).catch((error) => {
                        console.log('视频自动播放失败:', error);
                        this.hideLoading();
                        this.videoFailed = true;
                        this.bgVideo.style.display = 'none';
                        this.handleImageBackground();
                    });
                } else {
                    this.hideLoading();
                }
            }
            
            onVideoComplete() {
                // 视频播放完成，淡出视频
                console.log('视频播放完成，开始淡出');
                this.bgVideo.style.opacity = '0';
                this.bgVideo.style.transition = 'opacity 1s ease-out';
                
                setTimeout(() => {
                    this.bgVideo.style.display = 'none';
                    this.handleImageBackground();
                }, 1000);
            }
            
            handleImageBackground() {
                console.log('处理图片背景，规则顺序：');
                console.log('1. 自定义图片（如果有）');
                console.log('2. Bing背景（如果启用）');
                console.log('3. 默认背景（如果都没有）');
                
                // 规则1：如果有自定义背景图片，显示自定义图片
                if (this.customImage) {
                    console.log('使用规则1：显示自定义背景图片');
                    this.setBackgroundImage(this.customImage);
                    // 严格优先级：如果有自定义图片，不切换到Bing
                }
                // 规则2：如果没有自定义图片但启用了Bing背景，显示Bing图片并自动切换
                else if (this.useBing) {
                    console.log('使用规则2：显示Bing背景图片并开始自动切换');
                    this.loadInitialBingImage();
                }
                // 规则3：如果都没有，显示默认背景
                else {
                    console.log('使用规则3：显示默认背景');
                    this.bgImage.style.backgroundImage = 'none';
                    this.bgImage.style.backgroundColor = 'var(--bs-body-bg)';
                }
            }
            
            loadInitialBingImage() {
                this.showLoading();
                this.retryCount = 0;
                this.loadImageFromApi().then((imageUrl) => {
                    console.log('Bing图片加载成功');
                    this.hideLoading();
                    this.startImageRotation();
                }).catch((error) => {
                    console.log('加载Bing图片失败:', error);
                    this.hideLoading();
                    console.log('使用默认背景');
                    this.bgImage.style.backgroundImage = 'none';
                    this.bgImage.style.backgroundColor = 'var(--bs-body-bg)';
                    // 10秒后重试
                    setTimeout(() => {
                        this.loadInitialBingImage();
                    }, this.intervalTime);
                });
            }
            
            getCurrentApiUrl() {
                const deviceType = this.isMobile ? 'mobile' : 'desktop';
                const apiIndex = this.currentApiIndex[deviceType];
                const apiUrl = this.apiList[deviceType][apiIndex];
                console.log(`使用API: ${apiUrl} (索引: ${apiIndex})`);
                return apiUrl;
            }
            
            switchToNextApi() {
                const deviceType = this.isMobile ? 'mobile' : 'desktop';
                this.currentApiIndex[deviceType] = (this.currentApiIndex[deviceType] + 1) % this.apiList[deviceType].length;
                console.log(`切换到下一个API: ${this.getCurrentApiUrl()}`);
                this.retryCount = 0;
            }
            
            loadImageFromApi() {
                return new Promise((resolve, reject) => {
                    const apiUrl = this.getCurrentApiUrl();
                    console.log('请求API获取图片地址:', apiUrl);
                    
                    // 使用本地代理来请求API，解决Mixed Content和SSL问题
                    const proxyUrl = '/vendor/api/get_bg_url.php?url=' + encodeURIComponent(apiUrl);
                    
                    fetch(proxyUrl)
                        .then(response => response.json())
                        .then(data => {
                            console.log('API返回数据:', data);
                            let imageUrl = '';
                            
                            // 解析特定格式的API响应
                            if (data.success && data.data) {
                                // 根据设备类型选择合适的图片
                                if (this.isMobile && data.data.url_mobile) {
                                    imageUrl = data.data.url_mobile;
                                } else if (data.data.url) {
                                    imageUrl = data.data.url;
                                }
                            }
                            // 兼容直接返回url字段的情况
                            else if (data.url) {
                                imageUrl = data.url;
                            }
                            
                            if (imageUrl) {
                                // 确保图片URL是HTTPS的（如果可能）
                                if (imageUrl.startsWith('http://') && (imageUrl.includes('bing.com') || imageUrl.includes('bing.net'))) {
                                    imageUrl = imageUrl.replace('http://', 'https://');
                                }
                                console.log('解析到的图片URL:', imageUrl);
                                this.preloadAndSetImage(imageUrl, resolve);
                            } else {
                                console.log('未能解析出图片URL');
                                reject('API响应格式无法识别');
                            }
                        })
                        .catch(error => {
                            console.log('API请求失败或解析错误:', error);
                            reject(error);
                        });
                });
            }

            preloadAndSetImage(url, resolve) {
                const img = new Image();
                img.onload = () => {
                    console.log('图片加载成功');
                    this.setBackgroundImage(url);
                    resolve(url);
                };
                img.onerror = () => {
                    console.log('图片加载失败，尝试直接显示');
                    this.setBackgroundImage(url);
                    resolve(url);
                };
                img.src = url;
            }
            
            setBackgroundImage(imageUrl) {
                // 如果当前背景与要设置的背景相同，则跳过
                const currentBg = this.bgImage.style.backgroundImage;
                if (currentBg && currentBg.includes(imageUrl.replace(/'/g, "'"))) {
                    console.log('背景图片相同，跳过设置');
                    return;
                }
                
                console.log('设置背景图片:', imageUrl);
                
                // 设置过渡层的背景
                this.bgImageTransition.style.backgroundImage = `url('${imageUrl}')`;
                this.bgImageTransition.style.backgroundSize = 'cover';
                this.bgImageTransition.style.backgroundPosition = 'center';
                this.bgImageTransition.style.backgroundRepeat = 'no-repeat';
                
                // 强制重绘，确保过渡动画生效
                void this.bgImageTransition.offsetWidth;
                
                // 淡入过渡层
                this.bgImageTransition.classList.add('fade-in');
                
                // 等待淡入完成
                setTimeout(() => {
                    // 将新图片应用到底层
                    this.bgImage.style.backgroundImage = `url('${imageUrl}')`;
                    this.bgImage.style.backgroundSize = 'cover';
                    this.bgImage.style.backgroundPosition = 'center';
                    this.bgImage.style.backgroundRepeat = 'no-repeat';
                    
                    // 重置过渡层（无需动画，瞬间完成）
                    this.bgImageTransition.style.transition = 'none'; // 暂时禁用动画
                    this.bgImageTransition.classList.remove('fade-in');
                    this.bgImageTransition.style.opacity = '0';
                    this.bgImageTransition.style.backgroundImage = 'none';
                    
                    // 恢复动画属性
                    setTimeout(() => {
                        this.bgImageTransition.style.transition = 'opacity 1.5s ease-in-out';
                    }, 50);
                    
                }, 1500); // 1.5秒的淡入时间
            }
            
            showLoading() {
                if (this.bgLoading) {
                    this.bgLoading.style.display = 'block';
                }
            }
            
            hideLoading() {
                if (this.bgLoading) {
                    this.bgLoading.style.display = 'none';
                }
            }
            
            startImageRotation() {
                // 清除已有的定时器
                if (this.timer) {
                    clearInterval(this.timer);
                }
                
                console.log('开始10秒定时切换图片');
                this.timer = setInterval(() => {
                    this.switchToNextImage();
                }, this.intervalTime);
            }
            
            startBingImageRotation() {
                // 清除已有的定时器
                if (this.timer) {
                    clearInterval(this.timer);
                }
                
                console.log('开始Bing图片轮换（自定义图片+10秒后切换）');
                this.timer = setInterval(() => {
                    this.switchToNextImage();
                }, this.intervalTime);
            }
            
            switchToNextImage() {
                console.log('切换到下一张图片');
                this.showLoading();
                
                this.loadImageFromApi().then(() => {
                    this.hideLoading();
                }).catch((error) => {
                    console.log('切换图片失败:', error);
                    this.hideLoading();
                });
            }
            
            destroy() {
                if (this.timer) {
                    clearInterval(this.timer);
                    console.log('清除定时器');
                }
                if (this.bgVideo) {
                    this.bgVideo.pause();
                    console.log('暂停视频');
                }
                
                // 保存缓存信息到控制台（用于调试）
                if (this.videoCache) {
                    const cacheInfo = this.videoCache.getCacheInfo();
                    console.log('视频缓存信息:', cacheInfo);
                }
            }

            // 检查视频是否已更改（当页面重新加载或视频URL改变时调用）
            checkVideoChange() {
                if (!this.bgVideo) return false;
                
                const videoSource = this.bgVideo.querySelector('source');
                const newVideoUrl = videoSource ? videoSource.src : this.bgVideo.src;
                
                if (newVideoUrl && newVideoUrl !== this.currentVideoUrl) {
                    console.log('检测到视频URL改变:', this.currentVideoUrl, '->', newVideoUrl);
                    this.currentVideoUrl = newVideoUrl;
                    return true;
                }
                
                return false;
            }

            // 清理视频缓存（用于管理存储空间）
            clearVideoCache() {
                if (this.videoCache) {
                    localStorage.removeItem(this.videoCache.cacheName);
                    console.log('已清理所有视频缓存');
                }
            }
        }
        
            // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            console.log('页面加载完成，初始化背景管理器');
            

            
            // 初始化背景图片管理器
            const bgImageManager = new BackgroundImageManager();
            
            // 循环打字效果
            const introText = document.getElementById('introText');
            let text = introText.getAttribute('data-text');
            let index = 0;
            let isDeleting = false;
            let nextText = null;
            
            introText.textContent = '';
            
            function typeWriter() {
                if (!isDeleting && index < text.length) {
                    // 打字阶段
                    introText.textContent += text.charAt(index);
                    index++;
                    setTimeout(typeWriter, 100);
                } else if (!isDeleting && index === text.length) {
                    // 显示完成，等待2秒后开始删除
                    
                    // 提前获取下一次的文本
                    fetch('/vendor/api/get_intro.php')
                        .then(response => response.text())
                        .then(data => {
                            if (data && data.trim() !== '') {
                                nextText = data;
                            }
                        })
                        .catch(err => console.error('获取介绍文本失败:', err));

                    setTimeout(function() {
                        isDeleting = true;
                        typeWriter();
                    }, 2000);
                } else if (isDeleting && index > 0) {
                    // 删除阶段
                    introText.textContent = text.substring(0, index - 1);
                    index--;
                    setTimeout(typeWriter, 50);
                } else if (isDeleting && index === 0) {
                    // 删除完成，等待500毫秒后重新开始
                    isDeleting = false;
                    
                    if (nextText) {
                        text = nextText;
                        nextText = null;
                    }
                    
                    setTimeout(typeWriter, 500);
                }
            }
            
            // 延迟500毫秒后开始播放
            setTimeout(typeWriter, 500);
            
            // 页面卸载时清理资源
            window.addEventListener('beforeunload', () => {
                bgImageManager.destroy();
            });
            
            // 页面可见性变化处理
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    // 页面隐藏时暂停定时器和视频
                    console.log('页面隐藏，暂停背景切换');
                    bgImageManager.destroy();
                } else {
                    // 页面重新显示时检查视频是否改变
                    console.log('页面显示，检查视频变化');
                    if (bgImageManager.checkVideoChange()) {
                        // 视频已改变，重新初始化
                        console.log('视频已改变，重新初始化');
                        bgImageManager.init();
                    } else {
                        // 视频未改变，恢复背景切换
                        console.log('视频未改变，恢复背景切换');
                        if (bgImageManager.useBing && !bgImageManager.customImage) {
                            bgImageManager.startImageRotation();
                        } else if (bgImageManager.useBing && bgImageManager.customImage) {
                            bgImageManager.startBingImageRotation();
                        }
                        if (bgImageManager.bgVideo && bgImageManager.bgVideo.paused && !bgImageManager.videoEnded) {
                            bgImageManager.bgVideo.play();
                        }
                    }
                }
            });

            // 添加缓存管理快捷键（Ctrl+Shift+C）
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.shiftKey && e.key === 'C') {
                    e.preventDefault();
                    const cacheInfo = bgImageManager.videoCache.getCacheInfo();
                    console.group('视频缓存详情');
                    if (Object.keys(cacheInfo).length === 0) {
                        console.log('暂无视频缓存');
                    } else {
                        Object.entries(cacheInfo).forEach(([url, info]) => {
                            console.log(`URL: ${url}`);
                            console.log(`缓存时间: ${info.timestamp}`);
                            console.log(`大小: ${info.size}`);
                            console.log(`缓存时长: ${info.age} 分钟`);
                            console.log('---');
                        });
                    }
                    console.groupEnd();
                    
                    // 询问是否清理缓存
                    if (confirm('是否清理所有视频缓存？')) {
                        bgImageManager.clearVideoCache();
                        alert('视频缓存已清理');
                    }
                }
            });
        });
        
        // 加载QQ头像
        document.addEventListener('DOMContentLoaded', function() {
            const qq = "<?= e($config['contact_qq']) ?>";
            if (qq) {
                // 使用本地代理文件 vendor/api/qq_avatar.php
                fetch(`/vendor/api/qq_avatar.php?qq=${qq}&size=640`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.avatar_url) {
                            const heroAvatar = document.getElementById('heroAvatar');
                            if (heroAvatar) {
                                heroAvatar.src = data.avatar_url;
                            }
                        }
                    })
                    .catch(error => console.error('获取QQ头像失败:', error));
            }
        });

        // 自动处理外部链接，使用跳转页面
        function setupExternalLinks() {
            const currentDomain = window.location.hostname;
            const links = document.querySelectorAll('a[href^="http"]:not([href*="' + currentDomain + '"])');
            
            links.forEach(link => {
                const href = link.getAttribute('href');
                const linkText = link.textContent || link.innerText || '外部链接';
                
                // 跳过已经处理过的链接
                if (href.includes('/redirect.php')) {
                    return;
                }
                
                // 为外部链接添加跳转页面
                link.setAttribute('href', '/vendor/redirect.php?url=' + encodeURIComponent(href) + '&title=' + encodeURIComponent(linkText));
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');
            });
        }
        
        // 页面加载完成后设置外部链接
        document.addEventListener('DOMContentLoaded', function() {
            setupExternalLinks();
        });

        // 主题切换逻辑
        document.addEventListener('DOMContentLoaded', function() {
            const themeCheckbox = document.getElementById('theme-checkbox');
            const themeCheckboxMobile = document.getElementById('theme-checkbox-mobile');
            const htmlElement = document.documentElement;
            
            // 同步 Checkbox 状态
            function syncTheme(theme) {
                htmlElement.setAttribute('data-bs-theme', theme);
                localStorage.setItem('theme', theme);
                const isDark = theme === 'dark';
                if (themeCheckbox) themeCheckbox.checked = isDark;
                if (themeCheckboxMobile) themeCheckboxMobile.checked = isDark;
            }

            // 初始化
            const currentTheme = localStorage.getItem('theme') || htmlElement.getAttribute('data-bs-theme');
            if (currentTheme) {
                syncTheme(currentTheme);
            }
            
            // 监听切换事件
            function handleThemeChange(e) {
                const theme = e.target.checked ? 'dark' : 'light';
                syncTheme(theme);
            }

            if (themeCheckbox) themeCheckbox.addEventListener('change', handleThemeChange);
            if (themeCheckboxMobile) themeCheckboxMobile.addEventListener('change', handleThemeChange);

            // 优化移动端导航栏动画
            const navbarCollapse = document.getElementById('navbarNav');
            if (navbarCollapse) {
                // 展开开始
                navbarCollapse.addEventListener('show.bs.collapse', function () {
                    this.classList.add('animating-in');
                    this.classList.remove('animating-out');
                });
                
                // 展开结束
                navbarCollapse.addEventListener('shown.bs.collapse', function () {
                    this.classList.remove('animating-in');
                });
                
                // 收起开始
                navbarCollapse.addEventListener('hide.bs.collapse', function () {
                    this.classList.add('animating-out');
                    this.classList.remove('animating-in');
                });
                
                // 收起结束
                navbarCollapse.addEventListener('hidden.bs.collapse', function () {
                    this.classList.remove('animating-out');
                });
            }
        });

        // 动态处理下拉菜单交互
        document.addEventListener('DOMContentLoaded', function() {
            const dropdowns = document.querySelectorAll('.nav-item.dropdown .dropdown-toggle');
            
            function updateDropdownBehavior() {
                const isDesktop = window.innerWidth >= 992;
                
                dropdowns.forEach(dropdown => {
                    if (isDesktop) {
                        // 桌面端：移除 data-bs-toggle，允许 hover 生效，点击不触发折叠
                        if (dropdown.hasAttribute('data-bs-toggle')) {
                            dropdown.removeAttribute('data-bs-toggle');
                        }
                    } else {
                        // 移动端：添加 data-bs-toggle，点击触发折叠
                        if (!dropdown.hasAttribute('data-bs-toggle')) {
                            dropdown.setAttribute('data-bs-toggle', 'dropdown');
                        }
                    }
                });
            }
            
            // 初始化
            updateDropdownBehavior();
            
            // 监听窗口大小改变
            window.addEventListener('resize', updateDropdownBehavior);
        });
    </script>
    <script>
        // 复制内容到剪贴板
        function copyContent(elementId, btn) {
            const text = document.getElementById(elementId).innerText;
            
            // 优先使用 Clipboard API (需要 HTTPS 或 localhost 环境)
            // 如果没有 HTTPS，window.isSecureContext 会为 false，自动进入 else 分支使用旧方案
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showCopySuccess(btn);
                }).catch(err => {
                    console.error('复制失败:', err);
                    fallbackCopyText(text, btn);
                });
            } else {
                // 非 HTTPS 环境或不支持 Clipboard API，使用旧方案 (execCommand)
                fallbackCopyText(text, btn);
            }
        }
        
        // 降级复制方案 (适用于 HTTP 环境)
        function fallbackCopyText(text, btn) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            
            // 确保textarea不在视图中可见
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "0";
            
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showCopySuccess(btn);
                } else {
                    console.error('Fallback: Copy command was unsuccessful');
                    alert('复制失败，请手动复制');
                }
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
                alert('复制失败，请手动复制');
            }
            
            document.body.removeChild(textArea);
        }
        
        // 显示复制成功反馈
        function showCopySuccess(btn) {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>已复制';
            btn.classList.add('disabled');
            
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.remove('disabled');
            }, 2000);
        }
    </script>
    
    <!-- 公告弹窗 -->
    <?php if (!empty($config['website_announcement']) && !empty($config['website_announcement_enable']) && !empty($config['website_announcement_popup'])): ?>
    <?php 
    $announcementDate = !empty($config['website_announcement_date']) ? strtotime($config['website_announcement_date']) : 0;
    $currentUsername = isset($_SESSION['user_id']) ? $_SESSION['user_username'] : '';
    $announcementExcerpt = mb_substr(strip_tags($config['website_announcement']), 0, 150);
    ?>
    <div class="modal fade" id="announcementModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-megaphone-fill me-2"></i>🎊 号外:有新公告喽</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="关闭"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <p class="mb-3 text-muted" style="line-height: 1.8;"><?= e($announcementExcerpt) ?><?= mb_strlen(strip_tags($config['website_announcement'])) > 150 ? '...' : '' ?></p>
                    <small class="text-muted"><?= !empty($config['website_announcement_date']) ? '发布于 ' . date('Y-m-d H:i', strtotime($config['website_announcement_date'])) : '' ?></small>
                </div>
                <div class="modal-footer justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">
                        稍后再说
                    </button>
                    <a href="/vendor/announcement.php?from=<?= urlencode('/') ?>" class="btn btn-primary rounded-pill announcement-btn-gradient" id="announcementModalClose">
                        <i class="bi bi-arrow-right me-1"></i>查看详情
                    </a>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var announcementDate = <?= $announcementDate ?>;
        var currentUsername = '<?= e($currentUsername) ?>';
        
        // 已登录用户检查时间戳是否相同，未登录用户直接显示
        var shouldShow = true;
        if (currentUsername) {
            var storageKey = 'announcement_viewed_date_' + currentUsername;
            var viewedDate = parseInt(localStorage.getItem(storageKey) || '0');
            shouldShow = (announcementDate !== viewedDate);
        }
        
        if (shouldShow && document.getElementById('announcementModal')) {
            var modalEl = document.getElementById('announcementModal');
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
            
            // 点击查看详情按钮后记录
            document.getElementById('announcementModalClose').addEventListener('click', function() {
                if (currentUsername) {
                    var storageKey = 'announcement_viewed_date_' + currentUsername;
                    localStorage.setItem(storageKey, announcementDate.toString());
                }
            });
        }
    })();
    </script>
    <style>
    .announcement-btn-gradient {
        background: linear-gradient(135deg,rgb(238, 218, 167) 0%,rgb(247, 197, 159) 100%);
        border: none;
        color: #fff;
        transition: all 0.3s ease;
    }
    .announcement-btn-gradient:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        color: #fff;
    }
    </style>
    <?php endif; ?>
</body>
</html>