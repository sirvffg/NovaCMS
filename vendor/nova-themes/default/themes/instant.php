<?php
$pageTitle = '片刻';
$pageKey = 'instant';
$pageDescription = '没有写成长文的想法，也值得被认真保存。';
$communityCssVersion = (string)(@filemtime($themePath . '/assets/css/community.css') ?: 1);
$communityJsVersion = (string)(@filemtime($themePath . '/assets/js/community.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/community.css?v=' . e($communityCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/community.js?v=' . e($communityJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main community-page moments-page" data-community-page="moments">
    <section class="community-hero moments-hero">
        <div class="site-container community-hero-grid">
            <div data-reveal><span class="site-eyebrow"><i class="bi bi-chat-heart"></i> Small moments</span><h1>一些不成篇的片刻</h1><p>短句、照片和此刻的心情。它们很轻，却也是真实生活的一部分。</p></div>
            <blockquote class="moment-quote" data-reveal><i class="bi bi-quote"></i><p>保持敏感，也保持松弛。</p><span>— 写给日常</span></blockquote>
        </div>
    </section>
    <section class="community-section" aria-labelledby="moments-title">
        <div class="site-container moments-container">
            <header class="community-heading"><div><span class="site-eyebrow"><i class="bi bi-clock-history"></i> Timeline</span><h2 id="moments-title">最近动态</h2></div><p data-moment-count>正在读取片刻…</p></header>
            <div class="moment-timeline" data-moment-list aria-live="polite" aria-busy="true"><div class="community-skeleton skeleton-moment"></div><div class="community-skeleton skeleton-moment"></div></div>
            <div class="load-more-wrap"><button class="site-button site-button-quiet" type="button" data-moment-more hidden>继续往前看 <i class="bi bi-arrow-down"></i></button></div>
        </div>
    </section>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
