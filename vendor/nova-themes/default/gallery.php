<?php
$pageTitle = '相册';
$pageKey = 'gallery';
$pageDescription = '用照片收藏途中看到的光、颜色和瞬间。';
$currentAlbum = max(0, (int)($_GET['album'] ?? 0));
$communityCssVersion = (string)(@filemtime($themePath . '/assets/css/community.css') ?: 1);
$communityJsVersion = (string)(@filemtime($themePath . '/assets/js/community.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/community.css?v=' . e($communityCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/community.js?v=' . e($communityJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main community-page gallery-page" data-community-page="gallery" data-album-id="<?= $currentAlbum ?>">
    <section class="community-hero gallery-hero">
        <div class="site-container community-hero-grid">
            <div data-reveal>
                <span class="site-eyebrow"><i class="bi bi-camera"></i> Visual diary</span>
                <h1><?= $currentAlbum > 0 ? '相册里的片刻' : '光落下来的地方' ?></h1>
                <p>照片留住的不是完整故事，却总能把某个瞬间重新带回来。</p>
            </div>
            <div class="gallery-hero-stack" aria-hidden="true" data-reveal><span></span><span></span><span></span></div>
        </div>
    </section>
    <section class="community-section" aria-labelledby="gallery-title">
        <div class="site-container">
            <header class="community-heading">
                <div>
                    <?php if ($currentAlbum > 0): ?><a class="back-link" href="/gallery"><i class="bi bi-arrow-left"></i>全部相册</a><?php endif; ?>
                    <h2 id="gallery-title" data-gallery-title><?= $currentAlbum > 0 ? '正在打开相册…' : '全部相册' ?></h2>
                </div>
                <p data-gallery-count>正在整理照片…</p>
            </header>
            <div class="gallery-grid" data-gallery-grid aria-live="polite" aria-busy="true">
                <div class="community-skeleton skeleton-gallery"></div><div class="community-skeleton skeleton-gallery"></div><div class="community-skeleton skeleton-gallery"></div>
            </div>
            <div class="load-more-wrap">
                <button class="site-button site-button-quiet" type="button" data-gallery-more hidden>继续加载照片 <i class="bi bi-arrow-down"></i></button>
            </div>
        </div>
    </section>
    <dialog class="gallery-lightbox" data-gallery-lightbox aria-label="照片预览">
        <form method="dialog"><button class="lightbox-close" aria-label="关闭预览"><i class="bi bi-x-lg"></i></button></form>
        <button class="lightbox-nav is-previous" type="button" data-lightbox-prev aria-label="上一张"><i class="bi bi-chevron-left"></i></button>
        <figure><img alt=""><figcaption><strong data-lightbox-title></strong><span data-lightbox-description></span></figcaption></figure>
        <button class="lightbox-nav is-next" type="button" data-lightbox-next aria-label="下一张"><i class="bi bi-chevron-right"></i></button>
    </dialog>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
