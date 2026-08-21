<?php
$currentPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$isCurrentPath = static function (array $paths) use ($currentPath): bool {
    foreach ($paths as $path) {
        if ($path === '/' && ($currentPath === '/' || $currentPath === '/index.php')) {
            return true;
        }
        if ($path !== '/' && ($currentPath === $path || str_starts_with($currentPath, rtrim($path, '/') . '/'))) {
            return true;
        }
    }
    return false;
};
$homeActive = $isCurrentPath(['/']);
$blogActive = $isCurrentPath(['/blog', '/blog.php']);
$docsActive = $isCurrentPath(['/docs']);
$spaceActive = $isCurrentPath(['/friend-links', '/shuoshuo', '/gallery']);
$guestbookActive = $isCurrentPath(['/guestbook']);
$customPages = [];
if (function_exists('contentModuleListPublishedPages')) {
    try {
        $customPages = contentModuleListPublishedPages(true, 6);
    } catch (Throwable $exception) {
        error_log('Unable to load theme navigation pages: ' . $exception->getMessage());
        $customPages = [];
    }
}
$customPagesActive = str_starts_with($currentPath, '/page/');
?>
<nav id="nova-navbar" class="navbar navbar-expand-lg fixed-top nova-navbar" aria-label="主导航">
    <div class="container">
        <a class="navbar-brand nova-brand" href="/" aria-label="<?= e($siteName ?? ($config['website_name'] ?? 'NovaCMS')) ?>首页">
            <span class="nova-brand-mark" aria-hidden="true"><i class="bi bi-stars"></i></span>
            <span class="nova-brand-copy">
                <strong><?= e($siteName ?? ($config['website_name'] ?? 'NovaCMS')) ?></strong>
                <small>Ideas in progress</small>
            </span>
        </a>

        <div class="nova-nav-quick ms-auto d-flex d-lg-none">
            <button class="nova-icon-button" type="button" data-search-open aria-label="打开搜索" aria-haspopup="dialog">
                <i class="bi bi-search" aria-hidden="true"></i>
            </button>
            <button class="nova-icon-button" type="button" data-theme-toggle aria-label="切换为深色主题" aria-pressed="false">
                <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>
        </div>

        <button class="navbar-toggler nova-menu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#nova-primary-nav" aria-controls="nova-primary-nav" aria-expanded="false" aria-label="展开导航">
            <span></span><span></span><span></span>
        </button>

        <div class="collapse navbar-collapse" id="nova-primary-nav">
            <ul class="navbar-nav mx-lg-auto align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link<?= $homeActive ? ' active' : '' ?>" href="/"<?= $homeActive ? ' aria-current="page"' : '' ?>>首页</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $blogActive ? ' active' : '' ?>" href="/blog"<?= $blogActive ? ' aria-current="page"' : '' ?>>博客</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $docsActive ? ' active' : '' ?>" href="/docs"<?= $docsActive ? ' aria-current="page"' : '' ?>>文档</a>
                </li>
                <?php if ($customPages): ?>
                <li class="nav-item dropdown">
                    <button class="nav-link dropdown-toggle<?= $customPagesActive ? ' active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">页面</button>
                    <ul class="dropdown-menu nova-dropdown-menu">
                        <?php foreach ($customPages as $navigationPage):
                            $navigationPath = '/page/' . rawurlencode((string)$navigationPage['slug']);
                            $navigationActive = $currentPath === $navigationPath || $currentPath === $navigationPath . '/';
                        ?>
                        <li><a class="dropdown-item<?= $navigationActive ? ' active' : '' ?>" href="<?= e($navigationPath) ?>"<?= $navigationActive ? ' aria-current="page"' : '' ?>><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span><?= e($navigationPage['title']) ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <button class="nav-link dropdown-toggle<?= $spaceActive ? ' active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        空间
                    </button>
                    <ul class="dropdown-menu nova-dropdown-menu">
                        <li><a class="dropdown-item<?= $isCurrentPath(['/shuoshuo']) ? ' active' : '' ?>" href="/shuoshuo"><i class="bi bi-chat-quote" aria-hidden="true"></i><span>片刻</span></a></li>
                        <li><a class="dropdown-item<?= $isCurrentPath(['/gallery']) ? ' active' : '' ?>" href="/gallery"><i class="bi bi-images" aria-hidden="true"></i><span>相册</span></a></li>
                        <li><a class="dropdown-item<?= $isCurrentPath(['/friend-links']) ? ' active' : '' ?>" href="/friend-links"><i class="bi bi-link-45deg" aria-hidden="true"></i><span>友链</span></a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $guestbookActive ? ' active' : '' ?>" href="/guestbook"<?= $guestbookActive ? ' aria-current="page"' : '' ?>>留言</a>
                </li>
            </ul>

            <div class="nova-nav-actions d-flex align-items-lg-center">
                <button class="nova-search-trigger d-none d-lg-inline-flex" type="button" data-search-open aria-haspopup="dialog">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span>搜索</span>
                    <kbd>⌘ K</kbd>
                </button>
                <button class="nova-icon-button d-none d-lg-inline-grid" type="button" data-theme-toggle aria-label="切换为深色主题" aria-pressed="false">
                    <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
                </button>
                <div class="nova-nav-divider d-none d-lg-block" aria-hidden="true"></div>
                <div id="user-menu-nav" class="nova-user-menu" aria-live="polite">
                    <span class="nova-user-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="visually-hidden">正在读取登录状态</span></span>
                </div>
            </div>
        </div>
    </div>
</nav>
