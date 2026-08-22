<?php
$pageTitle = $contentPage['title'] ?? '页面';
$pageKey = 'page';
$pageDescription = !empty($contentPage['summary']) ? (string)$contentPage['summary'] : '站点独立页面。';
$pageTemplate = in_array($contentPage['template'] ?? '', ['wide', 'landing'], true) ? $contentPage['template'] : 'default';
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$contentJsVersion = (string)(@filemtime(__DIR__ . '/assets/js/content.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="/assets/js/marked.min.js"></script><script src="/vendor/nova-themes/default/themes/assets/js/content.js?v=' . e($contentJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main id="main-content" class="site-main content-page content-page-<?= e($pageTemplate) ?>" data-content-page="markdown" data-markdown-target="content-page-body">
    <div class="site-container content-page-container">
        <nav class="site-breadcrumb" aria-label="面包屑"><a href="/">首页</a><i class="bi bi-chevron-right"></i><span><?= e($contentPage['title'] ?? '') ?></span></nav>
        <article class="content-page-card">
            <header class="content-page-header">
                <span class="site-eyebrow"><i class="bi bi-file-earmark-text"></i> Page</span>
                <h1><?= e($contentPage['title'] ?? '') ?></h1>
                <?php if (!empty($contentPage['summary'])): ?><p><?= e($contentPage['summary']) ?></p><?php endif; ?>
                <?php if (!empty($contentPage['updated_at'])): ?><span class="content-page-date"><i class="bi bi-clock-history"></i>更新于 <?= e(date('Y-m-d', strtotime($contentPage['updated_at']))) ?></span><?php endif; ?>
            </header>
            <div id="content-page-body" class="article-content cms-markdown" data-markdown-source="<?= e(base64_encode((string)($contentPage['content'] ?? ''))) ?>" aria-live="polite"></div>
            <noscript><div class="article-content cms-markdown"><?= nl2br(e($contentPage['content'] ?? '')) ?></div></noscript>
        </article>
    </div>
</main>

<?php include $themePath . '/partials/footer.php'; ?>
