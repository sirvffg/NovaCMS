<?php
// Shared administration shell. Individual pages only provide their title and optional assets.
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isset($config) || empty($config)) {
    try {
        $db = getDB();
        $cfg = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $config = $cfg ?: [];
    } catch (Exception $e) {
        error_log('Unable to load admin shell config: ' . $e->getMessage());
        $config = [];
    }
}

$siteName = (string)($config['website_name'] ?? 'NovaCMS');
$pageHeading = isset($page_title) && $page_title !== '' ? (string)$page_title : '管理后台';
$documentTitle = $pageHeading . ' · ' . $siteName;
$adminUsername = (string)($_SESSION['admin_username'] ?? 'admin');
if (function_exists('mb_substr')) {
    $adminInitial = mb_substr($adminUsername, 0, 1, 'UTF-8');
} elseif (preg_match('/^./us', $adminUsername, $initialMatch)) {
    $adminInitial = $initialMatch[0];
} else {
    $adminInitial = substr($adminUsername, 0, 1);
}
$currentAdminPage = pathinfo(basename($_SERVER['PHP_SELF'] ?? 'index.php'), PATHINFO_FILENAME);
$adminCssPath = __DIR__ . '/../../assets/css/admin.css';
$adminCssVersion = is_file($adminCssPath) ? (string)filemtime($adminCssPath) : '1';

if (!defined('NOVA_BOOTSTRAP')) {
    define('NOVA_BOOTSTRAP', true);
}
require_once __DIR__ . '/../../vendor/nova-json/class/backend/class-backend-menu.php';

$primaryMenu = ['group' => 'primary', 'group_label' => '', 'group_order' => 0];
$contentMenu = ['group' => 'content', 'group_label' => '内容', 'group_order' => 100];
$systemMenu = ['group' => 'system', 'group_label' => '系统', 'group_order' => 200];
$toolMenu = ['group' => 'tools', 'group_label' => '工具', 'group_order' => 300];

Nova_Backend_Menu::add_menu('仪表盘', 'dashboard', '/admin/index.php', 'bi-speedometer2', 10, $primaryMenu);

Nova_Backend_Menu::add_menu('文章', 'posts', '', 'bi-layout-text-sidebar-reverse', 10, $contentMenu);
Nova_Backend_Menu::add_submenu('posts', '全部文章', 'posts_all', '/admin/posts.php', 10, ['icon' => 'bi-file-earmark-text']);
Nova_Backend_Menu::add_submenu('posts', '分类管理', 'categories', '/admin/categories.php', 20, ['icon' => 'bi-tags']);
Nova_Backend_Menu::add_menu('页面', 'pages', '/admin/pages.php', 'bi-window-sidebar', 20, $contentMenu);
Nova_Backend_Menu::add_menu('评论', 'comments', '/admin/comments.php', 'bi-chat-square-text', 30, $contentMenu);
Nova_Backend_Menu::add_menu('附件', 'files', '/admin/files.php', 'bi-folder2', 40, $contentMenu);
Nova_Backend_Menu::add_menu('图库', 'gallery', '/admin/gallery.php', 'bi-image', 50, $contentMenu);
Nova_Backend_Menu::add_menu('瞬间', 'shuoshuo', '/admin/shuoshuo.php', 'bi-globe2', 60, $contentMenu);
Nova_Backend_Menu::add_menu('文档', 'documents', '/admin/documents.php', 'bi-journal', 70, $contentMenu);
Nova_Backend_Menu::add_menu('链接', 'links', '/admin/links.php', 'bi-link-45deg', 80, $contentMenu);
Nova_Backend_Menu::add_menu('订阅', 'subscriptions', '/admin/subscriptions.php', 'bi-rss', 90, $contentMenu);
Nova_Backend_Menu::add_menu('智能问答', 'ai_qa', '', 'bi-stars', 100, $contentMenu);
Nova_Backend_Menu::add_submenu('ai_qa', '知识库', 'ai_knowledge', '/admin/ai_qa.php', 10, ['icon' => 'bi-database']);
Nova_Backend_Menu::add_submenu('ai_qa', '助手设置', 'ai_settings', '/admin/ai_settings.php', 20, ['icon' => 'bi-sliders2']);

Nova_Backend_Menu::add_menu('网站配置', 'config', '/admin/config.php', 'bi-sliders', 10, $systemMenu);
Nova_Backend_Menu::add_menu('用户管理', 'admins', '/admin/admins.php', 'bi-people', 20, $systemMenu);
Nova_Backend_Menu::add_menu('隐私与付费', 'privacy', '/admin/privacy_access.php', 'bi-shield-check', 30, $systemMenu);
Nova_Backend_Menu::add_menu('主题管理', 'themes', '/admin/themes.php', 'bi-palette', 40, $systemMenu);
Nova_Backend_Menu::add_menu('插件管理', 'plugins', '/admin/plugins.php', 'bi-puzzle', 50, $systemMenu);

Nova_Backend_Menu::add_menu('访问统计', 'stats', '/admin/stats.php', 'bi-bar-chart', 10, $toolMenu);
Nova_Backend_Menu::add_menu('留言管理', 'guestbook', '/admin/guestbook.php', 'bi-chat-left-dots', 20, $toolMenu);
Nova_Backend_Menu::add_menu('邮件测试', 'email_test', '/admin/email_test.php', 'bi-envelope-check', 30, $toolMenu);
Nova_Backend_Menu::add_menu('系统日志', 'view_logs', '/admin/view_logs.php', 'bi-journal-text', 50, $toolMenu);
Nova_Backend_Menu::add_menu(
    '系统状态',
    'system_status',
    '/admin/system_status.php',
    'bi-heart-pulse',
    55,
    $toolMenu
);
// 自动注册已启用插件的后台菜单（插件提供 plugin/admin/index.php 即可）
require_once __DIR__ . '/../../vendor/nova-json/class/plugin/class-plugin-registry.php';
$_novaActivePluginIds = null;
try {
    $_novaPluginDb = getDB();
    $_novaPluginCfg = $_novaPluginDb->query("SELECT active_plugins FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($_novaPluginCfg['active_plugins'])) {
        $_novaDecoded = json_decode($_novaPluginCfg['active_plugins'], true);
        if (is_array($_novaDecoded)) {
            $_novaActivePluginIds = $_novaDecoded;
        }
    }
} catch (Exception $e) {
    // 查询失败时保持全部启用
}
foreach (Nova_Plugin_Registry::scan_all() as $_novaPlugin) {
    // 跳过已禁用的插件
    if ($_novaActivePluginIds !== null && !in_array($_novaPlugin['id'], $_novaActivePluginIds, true)) {
        continue;
    }
    // 仅当插件提供后台管理页面且未声明 sidebar:false 时才注册侧边栏菜单
    $_novaPluginAdminFile = $_novaPlugin['plugin_dir'] . '/plugin/admin/index.php';
    if (!is_file($_novaPluginAdminFile)) {
        continue;
    }
    if (empty($_novaPlugin['sidebar'])) {
        continue;
    }
    Nova_Backend_Menu::add_menu(
        $_novaPlugin['name'],
        'plugin-' . $_novaPlugin['slug'],
        '/admin/plugin-page.php?plugin=' . urlencode($_novaPlugin['id']),
        'bi-puzzle',
        60,
        $toolMenu
    );
}
unset($_novaActivePluginIds, $_novaPluginDb, $_novaPluginCfg, $_novaDecoded, $_novaPlugin, $_novaPluginAdminFile);
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#11182c">
    <meta name="csrf-token" content="<?= e(generateCSRFToken()) ?>">
    <title><?= e($documentTitle) ?></title>
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('theme');
                var preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-bs-theme', savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : preferredTheme);
            } catch (error) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        }());
    </script>

    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/harmonyos-sans.css') ?>" rel="stylesheet">

    <?php if (!empty($extra_css)): ?>
    <style data-pjax-style><?= $extra_css ?></style>
    <?php endif; ?>
    <link href="/assets/css/admin.css?v=<?= e($adminCssVersion) ?>" rel="stylesheet">

    <?php if (!empty($head_scripts)): ?>
<!--nova-head-start--><?= preg_replace('/<script\b(?![^>]*type\s*=)/i', '<script type="text/pjax-script"', $head_scripts) ?><!--nova-head-end-->
    <?php endif; ?>
</head>
<body class="admin-shell" data-admin-page="<?= e($currentAdminPage) ?>">
    <a class="admin-skip-link" href="#main-content">跳到主内容</a>
    <div class="mobile-overlay" data-sidebar-overlay aria-hidden="true"></div>

    <aside class="sidebar admin-sidebar" id="sidebar" aria-label="后台主导航">
        <div class="sidebar-header">
            <a class="admin-brand" href="/admin/index.php" aria-label="返回仪表盘">
                <span class="brand-mark" aria-hidden="true">N</span>
                <span class="brand-copy">
                    <strong class="logo-text"><?= e($siteName) ?></strong>
                    <small>内容管理中心</small>
                </span>
            </a>
            <button class="sidebar-mobile-close" type="button" data-sidebar-close aria-label="关闭导航">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>

        <nav class="sidebar-scroll">
            <ul class="sidebar-menu">
                <?php Nova_Backend_Menu::render(); ?>
            </ul>
        </nav>
    </aside>

    <div class="main-content">
        <header class="top-bar">
            <div class="topbar-start">
                <button class="topbar-icon-button toggle-btn" type="button" data-sidebar-toggle aria-label="展开或收起导航" aria-controls="sidebar">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="topbar-context">
                    <span>管理后台</span>
                    <strong><?= e($pageHeading) ?></strong>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-search" type="button" data-command-open aria-label="搜索后台功能">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span>快速跳转</span>
                    <kbd>⌘ K</kbd>
                </button>
                <button class="topbar-icon-button" type="button" data-theme-toggle aria-label="切换深浅主题">
                    <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
                </button>
                <a class="topbar-icon-button" href="/" target="_blank" rel="noopener" aria-label="在新窗口查看网站">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                </a>
                <details class="admin-user-menu">
                    <summary aria-label="打开管理员菜单">
                        <span class="topbar-avatar" aria-hidden="true"><?= e(strtoupper($adminInitial ?: 'A')) ?></span>
                        <span class="topbar-user-copy">
                            <strong><?= e($adminUsername) ?></strong>
                            <small>管理员</small>
                        </span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <div class="admin-user-popover">
                        <div class="user-popover-heading">
                            <span class="topbar-avatar" aria-hidden="true"><?= e(strtoupper($adminInitial ?: 'A')) ?></span>
                            <div>
                                <strong><?= e($adminUsername) ?></strong>
                                <small>已登录管理控制台</small>
                            </div>
                        </div>
                        <a href="/admin/admins.php"><i class="bi bi-people" aria-hidden="true"></i>用户管理</a>
                        <a href="/" target="_blank" rel="noopener"><i class="bi bi-house" aria-hidden="true"></i>查看网站</a>
                        <form method="post" action="/admin/logout.php">
                            <?= csrfField() ?>
                            <button type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>安全退出</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main class="content-body" id="main-content" tabindex="-1">
            <div id="loading-overlay" class="active" role="status" aria-live="polite" aria-hidden="false">
                <div class="loading-panel">
                    <span class="loading-spinner" aria-hidden="true"></span>
                    <span data-loading-text>正在加载页面…</span>
                </div>
            </div>

            <div class="admin-toast-region" data-toast-region aria-live="polite" aria-atomic="true"></div>

            <dialog class="admin-command-dialog" data-command-dialog aria-label="快速跳转">
                <div class="command-dialog-head">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" data-command-input placeholder="搜索文章、用户、设置…" autocomplete="off">
                    <button type="button" data-command-close aria-label="关闭快速跳转">Esc</button>
                </div>
                <div class="command-dialog-body" data-command-results></div>
                <div class="command-dialog-foot">
                    <span><kbd>↑</kbd><kbd>↓</kbd> 选择</span>
                    <span><kbd>Enter</kbd> 打开</span>
                </div>
            </dialog>

            <div id="pjax-container" data-pjax-container>
<?php ob_start(); ?>
