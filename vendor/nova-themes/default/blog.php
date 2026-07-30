<?php
$postId = max(0, (int)($_GET['id'] ?? 0));
$isPostDetail = $postId > 0;
$pageTitle = $isPostDetail ? '文章阅读' : '博客';
$pageKey = 'blog';
$pageDescription = $isPostDetail ? '阅读文章详情、讨论与延伸内容。' : '文章、笔记与经验分享。';
$currentPage = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$currentSearch = trim((string)($_GET['q'] ?? ''));
$currentCategory = trim((string)($_GET['category'] ?? ''));
if (function_exists('mb_substr')) {
    $currentSearch = mb_substr($currentSearch, 0, 100, 'UTF-8');
    $currentCategory = mb_substr($currentCategory, 0, 100, 'UTF-8');
} else {
    $currentSearch = substr($currentSearch, 0, 100);
    $currentCategory = substr($currentCategory, 0, 100);
}
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$contentJsVersion = (string)(@filemtime($themePath . '/assets/js/content.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="/assets/js/marked.min.js"></script><script src="' . NOVA_THEME_URL . '/assets/js/content.js?v=' . e($contentJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<?php if ($isPostDetail): ?>
<main id="main-content" class="site-main article-page" data-content-page="blog-detail" data-post-id="<?= $postId ?>">
    <div class="site-container article-container">
        <nav class="site-breadcrumb" aria-label="面包屑">
            <a href="/">首页</a><i class="bi bi-chevron-right"></i><a href="/blog">博客</a><i class="bi bi-chevron-right"></i><span>文章</span>
        </nav>

        <article class="article-shell" aria-labelledby="article-title">
            <header class="article-header">
                <div class="article-labels" data-post-labels></div>
                <h1 id="article-title" data-post-title><span class="site-skeleton skeleton-title"></span></h1>
                <p class="article-summary" data-post-summary>正在准备这篇文章…</p>
                <div class="article-meta" data-post-meta aria-live="polite"></div>
            </header>
            <figure class="article-cover" data-post-cover hidden>
                <img alt="" loading="eager" decoding="async">
            </figure>
            <div class="article-layout">
                <div class="article-content cms-markdown" data-post-body aria-live="polite" aria-busy="true">
                    <div class="site-skeleton skeleton-line"></div>
                    <div class="site-skeleton skeleton-line is-short"></div>
                    <div class="site-skeleton skeleton-block"></div>
                </div>
                <aside class="article-aside" aria-label="文章工具">
                    <div class="article-aside-card">
                        <span class="small-label">阅读工具</span>
                        <button type="button" data-copy-link><i class="bi bi-link-45deg"></i>复制链接</button>
                        <button type="button" onclick="window.print()"><i class="bi bi-printer"></i>打印文章</button>
                        <a href="/blog"><i class="bi bi-arrow-left"></i>返回文章列表</a>
                    </div>
                    <div class="article-progress" aria-hidden="true"><span data-reading-progress></span></div>
                </aside>
            </div>
        </article>

        <section class="comments-panel" aria-labelledby="comments-title">
            <header class="section-heading compact">
                <div><span class="site-eyebrow"><i class="bi bi-chat-square-text"></i> Discussion</span><h2 id="comments-title">评论与交流</h2></div>
                <span class="comment-count" data-comment-count>0 条</span>
            </header>
            <form class="comment-form" data-comment-form>
                <label for="comment-content">写下你的想法</label>
                <textarea id="comment-content" name="content" rows="4" maxlength="2000" placeholder="保持友善，也欢迎补充不同视角。" required></textarea>
                <div class="comment-form-footer">
                    <p data-comment-feedback aria-live="polite">登录后即可参与讨论。</p>
                    <button class="site-button site-button-primary" type="submit">发表评论</button>
                </div>
            </form>
            <div class="comment-list" data-comment-list aria-live="polite" aria-busy="true">
                <div class="site-skeleton skeleton-comment"></div>
            </div>
        </section>
    </div>

    <dialog class="site-dialog privacy-dialog" data-privacy-dialog aria-labelledby="privacy-dialog-title">
        <form method="dialog" class="dialog-close-form"><button aria-label="关闭"><i class="bi bi-x-lg"></i></button></form>
        <span class="dialog-icon"><i class="bi bi-shield-lock"></i></span>
        <h2 id="privacy-dialog-title">申请阅读私密内容</h2>
        <p data-privacy-question>请回答作者设置的问题。</p>
        <form data-privacy-form>
            <label for="privacy-answer">你的回答</label>
            <textarea id="privacy-answer" name="answer" rows="4" maxlength="1000" required></textarea>
            <p class="form-feedback" data-privacy-feedback aria-live="polite"></p>
            <button class="site-button site-button-primary" type="submit">提交申请</button>
        </form>
    </dialog>
</main>
<?php else: ?>
<main id="main-content" class="site-main blog-page" data-content-page="blog-list" data-page="<?= $currentPage ?>" data-search="<?= e($currentSearch) ?>" data-category="<?= e($currentCategory) ?>">
    <section class="page-hero blog-hero">
        <div class="site-container page-hero-grid">
            <div data-reveal>
                <span class="site-eyebrow"><i class="bi bi-journal-richtext"></i> Writing archive</span>
                <h1>文章与笔记</h1>
                <p>这里收录技术实践、产品思考，也记录那些值得反复回看的小事。</p>
            </div>
            <form class="hero-search" method="get" action="/blog" role="search" data-reveal>
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="blog-search">搜索文章</label>
                <input id="blog-search" type="search" name="q" value="<?= e($currentSearch) ?>" placeholder="搜索标题或正文…" maxlength="100">
                <button type="submit">搜索</button>
            </form>
        </div>
    </section>

    <section class="site-section blog-archive" aria-labelledby="archive-title">
        <div class="site-container">
            <div class="blog-toolbar">
                <div>
                    <h2 id="archive-title"><?= $currentSearch !== '' ? '搜索结果' : ($currentCategory !== '' ? e($currentCategory) : '全部文章') ?></h2>
                    <p data-blog-count aria-live="polite">正在整理文章…</p>
                </div>
                <nav class="filter-chips" data-blog-categories aria-label="文章分类">
                    <a class="is-active" href="<?= e($currentSearch === '' ? '/blog' : '/blog?q=' . rawurlencode($currentSearch)) ?>">全部</a>
                </nav>
            </div>

            <div class="post-grid" data-blog-posts aria-live="polite" aria-busy="true">
                <div class="site-skeleton post-card-skeleton"></div>
                <div class="site-skeleton post-card-skeleton"></div>
                <div class="site-skeleton post-card-skeleton"></div>
                <div class="site-skeleton post-card-skeleton"></div>
            </div>
            <nav class="site-pagination" data-blog-pagination aria-label="文章分页"></nav>
        </div>
    </section>
</main>
<?php endif; ?>

<?php include $themePath . '/partials/footer.php'; ?>
