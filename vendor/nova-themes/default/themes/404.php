<?php
$pageTitle = '页面未找到';
$pageKey = 'error';
$pageDescription = '你访问的页面可能已经移动，或者从未存在。';
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main error-page">
    <div class="site-container error-layout">
        <div class="error-illustration" aria-hidden="true">
            <span>4</span><span class="error-orbit"><i class="bi bi-compass"></i></span><span>4</span>
        </div>
        <div class="error-copy">
            <span class="site-eyebrow"><i class="bi bi-signpost-split"></i> Lost in the archive</span>
            <h1>这条路径没有抵达页面。</h1>
            <p>它可能已经换了位置。你可以回到首页，或继续浏览最近的文章。</p>
            <div class="error-actions">
                <a class="site-button site-button-primary" href="/"><i class="bi bi-house-door"></i>返回首页</a>
                <a class="site-button site-button-quiet" href="/blog">浏览文章</a>
            </div>
        </div>
    </div>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
