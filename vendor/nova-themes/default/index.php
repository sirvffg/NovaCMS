<?php
$homeSiteName = trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS';
$homeAuthor = trim((string)($config['website_author'] ?? '')) ?: $homeSiteName;
$homeDescription = trim(strip_tags((string)($config['description'] ?? '')));
if ($homeDescription === '') {
    $homeDescription = trim(strip_tags((string)($config['robot_description'] ?? '')));
}
if ($homeDescription === '') {
    $homeDescription = '记录技术实践、生活片段与仍在持续生长的想法。';
}
if (function_exists('contentModuleSubstring')) {
    $homeAuthor = contentModuleSubstring($homeAuthor, 0, 80);
    $homeDescription = contentModuleSubstring($homeDescription, 0, 220);
}
if (function_exists('mb_substr')) {
    $homeAuthorInitial = mb_substr($homeAuthor, 0, 1, 'UTF-8');
} elseif (preg_match('/^./us', $homeAuthor, $homeAuthorMatch)) {
    $homeAuthorInitial = $homeAuthorMatch[0];
} else {
    $homeAuthorInitial = substr($homeAuthor, 0, 1);
}
$homeContactEmail = filter_var(trim((string)($config['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
$pageTitle = '首页';
$pageKey = 'home';
$pageDescription = $homeDescription;
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$contentJsVersion = (string)(@filemtime($themePath . '/assets/js/content.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/content.js?v=' . e($contentJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main id="main-content" class="site-main home-page home-blog-page" data-content-page="home">
    <section class="home-blog-masthead" aria-labelledby="home-title">
        <div class="site-container home-blog-masthead-grid">
            <div class="home-blog-intro" data-reveal>
                <span class="site-eyebrow"><i class="bi bi-journal-text" aria-hidden="true"></i> Personal blog</span>
                <h1 id="home-title"><?= e($homeSiteName) ?></h1>
                <p><?= e($homeDescription) ?></p>
                <div class="home-author-line">
                    <span class="home-author-avatar" aria-hidden="true"><?= e($homeAuthorInitial) ?></span>
                    <span><small>作者</small><strong><?= e($homeAuthor) ?></strong></span>
                    <span class="home-author-divider" aria-hidden="true"></span>
                    <a href="/license/rss.php"><i class="bi bi-rss" aria-hidden="true"></i>订阅 RSS</a>
                </div>
                <div class="home-blog-actions">
                    <a class="site-button site-button-primary" href="/blog">浏览全部文章 <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    <a class="site-button site-button-quiet" href="/guestbook">给我留言 <i class="bi bi-chat-dots" aria-hidden="true"></i></a>
                </div>
            </div>

            <aside class="home-blog-note" aria-label="博客内容方向" data-reveal>
                <span class="home-note-label"><i class="bi bi-bookmark-heart" aria-hidden="true"></i> 本博客主要记录</span>
                <ul>
                    <li><span>01</span><strong>技术与实践</strong><small>开发、工具与项目复盘</small></li>
                    <li><span>02</span><strong>思考与经验</strong><small>产品、设计与长期成长</small></li>
                    <li><span>03</span><strong>生活与片刻</strong><small>阅读、影像与日常观察</small></li>
                </ul>
            </aside>
        </div>
    </section>

    <section class="home-blog-content" aria-labelledby="latest-title">
        <div class="site-container">
            <header class="home-blog-heading" data-reveal>
                <div>
                    <span class="site-eyebrow"><i class="bi bi-clock-history" aria-hidden="true"></i> Latest posts</span>
                    <h2 id="latest-title">最新文章</h2>
                    <p>按发布时间整理最近的写作与更新。</p>
                </div>
                <a class="section-link" href="/blog">文章归档 <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </header>

            <div class="home-blog-layout">
                <section class="home-post-feed" aria-label="最新文章列表">
                    <div class="home-post-list" data-home-posts aria-live="polite" aria-busy="true">
                        <div class="site-skeleton home-post-skeleton"></div>
                        <div class="site-skeleton home-post-skeleton"></div>
                        <div class="site-skeleton home-post-skeleton"></div>
                    </div>
                    <a class="home-archive-link" href="/blog">
                        <span><strong>继续浏览文章归档</strong><small>查看全部文章、分类与搜索结果</small></span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </section>

                <aside class="home-blog-sidebar" aria-label="博客侧栏">
                    <section class="blog-widget author-widget">
                        <div class="author-widget-heading">
                            <span class="author-widget-avatar" aria-hidden="true"><?= e($homeAuthorInitial) ?></span>
                            <div><small>ABOUT THE AUTHOR</small><h2><?= e($homeAuthor) ?></h2></div>
                        </div>
                        <p><?= e($homeDescription) ?></p>
                        <div class="author-widget-links">
                            <?php if ($homeContactEmail !== ''): ?>
                                <a href="mailto:<?= e($homeContactEmail) ?>"><i class="bi bi-envelope" aria-hidden="true"></i>联系作者</a>
                            <?php endif; ?>
                            <a href="/friend-links"><i class="bi bi-link-45deg" aria-hidden="true"></i>友情链接</a>
                        </div>
                    </section>

                    <section class="blog-widget category-widget" aria-labelledby="home-categories-title">
                        <header><h2 id="home-categories-title">文章分类</h2><a href="/blog">全部</a></header>
                        <nav class="home-category-list" data-home-categories aria-label="文章分类" aria-live="polite" aria-busy="true">
                            <span class="site-skeleton category-skeleton"></span>
                            <span class="site-skeleton category-skeleton"></span>
                            <span class="site-skeleton category-skeleton"></span>
                        </nav>
                    </section>

                    <section class="blog-widget quick-widget" aria-labelledby="home-explore-title">
                        <h2 id="home-explore-title">更多内容</h2>
                        <nav>
                            <a href="/docs"><span><i class="bi bi-book" aria-hidden="true"></i><strong>文档中心</strong></span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                            <a href="/shuoshuo"><span><i class="bi bi-chat-heart" aria-hidden="true"></i><strong>片刻动态</strong></span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                            <a href="/gallery"><span><i class="bi bi-images" aria-hidden="true"></i><strong>影像相册</strong></span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                        </nav>
                    </section>

                    <section class="blog-widget newsletter-widget" aria-labelledby="subscribe-title">
                        <span class="newsletter-widget-icon"><i class="bi bi-send" aria-hidden="true"></i></span>
                        <h2 id="subscribe-title">订阅博客更新</h2>
                        <p>新文章发布时，发送一封简短通知。</p>
                        <form class="home-newsletter-form" data-subscribe-form novalidate>
                            <label class="visually-hidden" for="subscribe-email">邮箱地址</label>
                            <input id="subscribe-email" name="email" type="email" autocomplete="email" maxlength="191" placeholder="name@example.com" required>
                            <button class="site-button site-button-primary" type="submit">订阅</button>
                            <p class="form-feedback" data-subscribe-feedback aria-live="polite"></p>
                        </form>
                    </section>
                </aside>
            </div>
        </div>
    </section>
</main>

<?php include $themePath . '/partials/footer.php'; ?>
