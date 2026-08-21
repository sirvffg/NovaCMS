<?php
$pageTitle = '个人中心';
$pageKey = 'profile';
$pageDescription = '查看账户信息、登录状态与常用入口。';
$communityCssVersion = (string)(@filemtime($themePath . '/assets/css/community.css') ?: 1);
$communityJsVersion = (string)(@filemtime($themePath . '/assets/js/community.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/community.css?v=' . e($communityCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/community.js?v=' . e($communityJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main community-page profile-page" data-community-page="profile">
    <section class="profile-shell">
        <div class="site-container" data-profile-root aria-live="polite" aria-busy="true">
            <div class="profile-loading"><div class="community-skeleton profile-skeleton-card"></div><div class="community-skeleton profile-skeleton-panel"></div></div>
        </div>
    </section>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
