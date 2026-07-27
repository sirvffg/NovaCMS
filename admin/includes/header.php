<?php
// Ensure core functions are available (require_once won't reload if already loaded)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

// Load website config from DB if not already loaded by the page
if (!isset($config) || empty($config)) {
    try {
        $db = getDB();
        $cfg = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($cfg) {
            $config = $cfg;
        }
    } catch (Exception $e) {
        $config = [];
    }
}

$page_title = isset($page_title) ? e($page_title) . ' - ' . e($config['website_name'] ?? '后台管理') : e($config['website_name'] ?? '后台管理');

// =============================================
// 动态注册后台侧边栏菜单
// =============================================
require_once __DIR__ . '/../../vendor/nova-json/class/backend/class-backend-menu.php';

Nova_Backend_Menu::add_menu('仪表盘',     'dashboard',   '/admin/index.php',           '仪', 10);
Nova_Backend_Menu::add_menu('网站配置',   'config',      '/admin/config.php',          '网', 20);
Nova_Backend_Menu::add_menu('博客管理',   'posts',       '/admin/posts.php',           '博', 30);
Nova_Backend_Menu::add_menu('隐私与付费记录', 'privacy', '/admin/privacy_access.php',  '隐', 40);
Nova_Backend_Menu::add_menu('用户管理',   'admins',      '/admin/admins.php',          '用', 50);
Nova_Backend_Menu::add_menu('友情链接',   'links',       '/admin/links.php',           '友', 60);
Nova_Backend_Menu::add_menu('访问统计',   'stats',       '/admin/stats.php',           '访', 70);
Nova_Backend_Menu::add_menu('留言管理',   'guestbook',   '/admin/guestbook.php',       '留', 80);
Nova_Backend_Menu::add_menu('说说管理',   'shuoshuo',    '/admin/shuoshuo.php',        '说', 90);
Nova_Backend_Menu::add_menu('相册管理',   'gallery',     '/admin/gallery.php',         '相', 100);
Nova_Backend_Menu::add_menu('QQ群管理',   'qq_groups',   '/admin/qq_groups.php',       'Q',  110);

// 其他设置（含子菜单）
Nova_Backend_Menu::add_menu('其他设置',   'settings',    '',                           '其', 130);
Nova_Backend_Menu::add_submenu('settings', '一言管理',   'hitokoto',  '/admin/hitokoto.php',    20);
Nova_Backend_Menu::add_submenu('settings', '监控管理',   'monitors',  '/admin/monitors.php',    30);
Nova_Backend_Menu::add_submenu('settings', '文件管理',   'files',     '/admin/files.php',       40);
Nova_Backend_Menu::add_submenu('settings', '邮件测试',   'email_test','/admin/email_test.php',   50);
Nova_Backend_Menu::add_submenu('settings', 'SEO 工具集', 'seo_tools', '/admin/seo_tools.php',    60);
Nova_Backend_Menu::add_submenu('settings', '系统日志查看','view_logs', '/admin/view_logs.php',    70);
Nova_Backend_Menu::add_submenu('settings', '开源版本管理','opensource', '/admin/opensource.php',   80);

// 外部链接
Nova_Backend_Menu::add_menu('查看网站',   'view-site',   '/',                           '查', 140, ['target' => '_blank']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>

    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>

    <!-- Bootstrap CSS -->
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">

    <?php if (!empty($extra_css)): ?>
    <style><?= $extra_css ?></style>
    <?php endif; ?>

    <?php if (!empty($head_scripts)): ?>
    <?= $head_scripts ?>
    <?php endif; ?>

    <style>
        :root {
            --primary-color: #1890ff;
            --sidebar-width: 240px;
            --sidebar-collapsed-width: 64px;
            --header-height: 64px;
            --bg-color: #f0f2f5;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-color);
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        /* ===== Sidebar ===== */
        .sidebar {
            width: var(--sidebar-width);
            background: #fff;
            box-shadow: 2px 0 8px 0 rgba(29,35,41,.05);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            transition: width 0.3s cubic-bezier(0.2, 0, 0, 1);
            z-index: 10;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }
        body.collapsed .sidebar { width: var(--sidebar-collapsed-width); }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
            overflow: hidden;
            flex-shrink: 0;
            padding: 0 20px;
        }
        .logo-text {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            opacity: 1;
            transition: opacity 0.3s;
            white-space: nowrap;
        }
        body.collapsed .logo-text { opacity: 0; display: none; }

        .sidebar-menu {
            list-style: none;
            padding: 16px 0;
            margin: 0;
            flex: 1;
        }
        .sidebar-menu > li { position: relative; }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            height: 44px;
            padding: 0 24px;
            color: #666;
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
            overflow: hidden;
            font-size: 14px;
            cursor: pointer;
        }
        .sidebar-menu li a:hover { color: var(--primary-color); background: #f5f7fa; }
        .sidebar-menu li a.active {
            color: var(--primary-color);
            background: #e6f7ff;
            border-right: 3px solid var(--primary-color);
        }

        .menu-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
            margin-right: 8px;
            background: #f0f2f5;
            color: #666;
            transition: all 0.3s;
        }
        .sidebar-menu li a.active .menu-icon {
            background: #1890ff;
            color: #fff;
        }
        .menu-text { white-space: nowrap; }
        body.collapsed .menu-text { display: none; }
        body.collapsed .menu-icon { margin-right: 0; }
        body.collapsed .sidebar-menu li a { justify-content: center; padding: 0; }

        /* Submenu */
        .submenu-toggle { position: relative; }
        .submenu-arrow {
            margin-left: auto;
            font-size: 12px;
            transition: transform 0.3s;
            opacity: 0.6;
        }
        body.collapsed .submenu-arrow { display: none; }
        .submenu-toggle.open .submenu-arrow { transform: rotate(90deg); }

        .submenu {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease;
            background: #fafbfc;
        }
        .submenu-toggle.open + .submenu { max-height: 800px; }
        body.collapsed .submenu { max-height: 0 !important; }

        .submenu li a {
            padding-left: 60px;
            font-size: 13px;
            height: 38px;
            color: #777;
        }
        .submenu li a:hover { color: var(--primary-color); background: #f0f4f8; }
        body.collapsed .submenu li a { padding-left: 24px; }

        /* ===== Main Content ===== */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            width: 0;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s cubic-bezier(0.2, 0, 0, 1);
        }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed-width); }

        /* ===== Top Bar ===== */
        .top-bar {
            height: var(--header-height);
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,21,41,.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            flex-shrink: 0;
            z-index: 5;
        }
        .toggle-btn {
            cursor: pointer;
            font-size: 20px;
            color: #999;
            transition: color 0.3s;
            display: flex;
            align-items: center;
            background: none;
            border: none;
            padding: 4px;
            border-radius: 4px;
        }
        .toggle-btn:hover { color: var(--primary-color); background: #f0f4f8; }
        .user-info { margin-right: 20px; color: #666; font-size: 14px; }
        .top-links { display: flex; align-items: center; }
        .top-links a {
            color: #666;
            text-decoration: none;
            margin-left: 20px;
            font-size: 14px;
            transition: color 0.3s;
        }
        .top-links a:hover { color: var(--primary-color); }
        .top-links a.logout:hover { color: #ff4d4f; }

        /* ===== Content Body ===== */
        .content-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        /* ===== Loading Overlay ===== */
        #loading-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            flex-direction: column;
            gap: 12px;
        }
        #loading-overlay.active { display: flex; }
        .loading-spinner {
            width: 36px;
            height: 36px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== Mobile Responsive ===== */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                left: 0; top: 0;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                box-shadow: 2px 0 12px rgba(0,0,0,0.1);
                z-index: 20;
            }
            body.mobile-open .sidebar { transform: translateX(0); }
            .mobile-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.4);
                z-index: 15;
                backdrop-filter: blur(2px);
            }
            body.mobile-open .mobile-overlay { display: block; }
            body.collapsed .sidebar { width: var(--sidebar-width); transform: translateX(0); }
            body.collapsed .logo-text { opacity: 1; display: block; }
            body.collapsed .menu-text { display: block; }
            body.collapsed .submenu { max-height: 800px !important; }
            body.collapsed .submenu-arrow { display: inline-block; }
            .main-content { width: 100%; min-width: 100%; margin-left: 0; }
            .content-body { padding: 16px; }
            .top-bar { padding: 0 15px; }
            .user-info { display: none; }
            .top-links a { margin-left: 10px; font-size: 13px; }
        }
    </style>
</head>
<body>
    <div class="mobile-overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <span class="logo-text"><?= e($config['website_name'] ?? '后台管理') ?></span>
        </div>
        <ul class="sidebar-menu">
            <?php Nova_Backend_Menu::render(); ?>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-content">
        <header class="top-bar">
            <button class="toggle-btn" onclick="toggleSidebar()" title="折叠/展开菜单">
                <svg viewBox="0 0 1024 1024" width="20" height="20" fill="currentColor">
                    <path d="M904 160H120c-4.4 0-8 3.6-8 8v64c0 4.4 3.6 8 8 8h784c4.4 0 8-3.6 8-8v-64c0-4.4-3.6-8-8-8zm0 624H120c-4.4 0-8 3.6-8 8v64c0 4.4 3.6 8 8 8h784c4.4 0 8-3.6 8-8v-64c0-4.4-3.6-8-8-8zm0-312H120c-4.4 0-8 3.6-8 8v64c0 4.4 3.6 8 8 8h784c4.4 0 8-3.6 8-8v-64c0-4.4-3.6-8-8-8z"/>
                </svg>
            </button>
            <div class="top-links">
                <span class="user-info">欢迎 <?= htmlspecialchars($_SESSION['admin_username'] ?? 'admin') ?></span>
                <a href="/" target="_blank">查看首页</a>
                <a href="/admin/logout.php" class="logout">退出登录</a>
            </div>
        </header>
        <div class="content-body">
            <div id="loading-overlay">
                <div class="loading-spinner"></div>
                <span>处理中..</span>
            </div>
