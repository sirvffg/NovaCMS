<?php
$pageTitle = '友情链接';
$pageKey = 'friend-links';
$pageDescription = '认识仍在认真写作、创造与分享的人。';
$communityCssVersion = (string)(@filemtime($themePath . '/assets/css/community.css') ?: 1);
$communityJsVersion = (string)(@filemtime($themePath . '/assets/js/community.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/community.css?v=' . e($communityCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/community.js?v=' . e($communityJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main community-page links-page" data-community-page="links">
    <section class="community-hero links-hero">
        <div class="site-container community-hero-grid">
            <div data-reveal>
                <span class="site-eyebrow"><i class="bi bi-link-45deg"></i> Neighbours on the web</span>
                <h1>互联网上的好邻居</h1>
                <p>这些站点仍在认真地记录、创造与分享。沿着链接出发，也许会遇见新的灵感。</p>
            </div>
            <div class="community-hero-mark" aria-hidden="true" data-reveal>
                <i class="bi bi-globe2"></i><span></span><i class="bi bi-stars"></i>
            </div>
        </div>
    </section>
    <section class="community-section" aria-labelledby="links-title">
        <div class="site-container">
            <header class="community-heading">
                <div><span class="site-eyebrow"><i class="bi bi-people"></i> Friends</span><h2 id="links-title">友情链接</h2></div>
                <p data-link-count>正在寻找邻居…</p>
            </header>
            <div class="link-groups" data-link-groups aria-live="polite" aria-busy="true">
                <div class="community-skeleton skeleton-link-grid"></div>
            </div>
            <aside class="link-apply-panel" data-reveal>
                <div><span class="link-apply-icon"><i class="bi bi-plus-lg"></i></span><h2>想成为邻居？</h2><p>如果你也在维护一块长期更新的小站，欢迎提交友链申请。</p></div>
                <a class="site-button site-button-primary" href="/vendor/appeal.php">申请友链 <i class="bi bi-arrow-up-right"></i></a>
            </aside>
        </div>
    </section>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
