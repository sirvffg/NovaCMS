<?php
$pageTitle = $document['title'] ?? '文档';
$pageKey = 'docs';
$pageDescription = !empty($document['summary']) ? (string)$document['summary'] : '阅读文档详情。';
$downloadUrl = contentModuleSafeUrl($document['file_url'] ?? '');
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$contentJsVersion = (string)(@filemtime(__DIR__ . '/assets/js/content.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="/assets/js/marked.min.js"></script><script src="/vendor/nova-themes/default/themes/assets/js/content.js?v=' . e($contentJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main id="main-content" class="site-main document-page" data-content-page="markdown" data-markdown-target="document-body">
    <div class="site-container article-container">
        <nav class="site-breadcrumb" aria-label="面包屑">
            <a href="/">首页</a><i class="bi bi-chevron-right"></i><a href="/docs">文档中心</a>
            <?php if (!empty($document['category'])): ?><i class="bi bi-chevron-right"></i><a href="/docs?category=<?= rawurlencode($document['category']) ?>"><?= e($document['category']) ?></a><?php endif; ?>
        </nav>

        <div class="document-layout">
            <article class="document-main">
                <header class="document-header">
                    <div class="article-labels">
                        <?php if (!empty($document['is_featured'])): ?><span class="site-badge is-featured"><i class="bi bi-star-fill"></i>推荐文档</span><?php endif; ?>
                        <?php if (!empty($document['category'])): ?><span class="site-badge"><?= e($document['category']) ?></span><?php endif; ?>
                    </div>
                    <h1><?= e($document['title'] ?? '') ?></h1>
                    <?php if (!empty($document['summary'])): ?><p><?= e($document['summary']) ?></p><?php endif; ?>
                    <div class="article-meta">
                        <?php if (!empty($document['updated_at'])): ?><span><i class="bi bi-clock-history"></i>更新于 <?= e(date('Y-m-d', strtotime($document['updated_at']))) ?></span><?php endif; ?>
                        <span><i class="bi bi-download"></i><?= (int)($document['download_count'] ?? 0) ?> 次下载</span>
                    </div>
                </header>

                <div id="document-body" class="article-content cms-markdown" data-markdown-source="<?= e(base64_encode((string)($document['content'] ?? ''))) ?>" aria-live="polite"></div>
                <noscript><div class="article-content cms-markdown"><?= nl2br(e($document['content'] ?? '')) ?></div></noscript>
            </article>

            <aside class="document-aside" aria-label="文档操作">
                <div class="article-aside-card">
                    <span class="small-label">文档操作</span>
                    <?php if ($downloadUrl !== ''): ?><a class="is-primary" href="/docs/<?= rawurlencode($document['slug']) ?>/download"><i class="bi bi-download"></i>下载附件</a><?php endif; ?>
                    <button type="button" data-copy-link><i class="bi bi-link-45deg"></i>复制链接</button>
                    <button type="button" onclick="window.print()"><i class="bi bi-printer"></i>打印文档</button>
                    <a href="/docs"><i class="bi bi-arrow-left"></i>返回文档中心</a>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php include $themePath . '/partials/footer.php'; ?>
