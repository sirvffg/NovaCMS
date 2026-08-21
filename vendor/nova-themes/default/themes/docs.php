<?php
$pageTitle = '文档中心';
$pageKey = 'docs';
$pageDescription = '查找使用指南、帮助文档与可下载资料。';
$documentResults = is_array($documentResults ?? null) ? $documentResults : [
    'items' => [], 'page' => 1, 'total' => 0, 'total_pages' => 1,
];
$documentCategories = is_array($documentCategories ?? null) ? $documentCategories : [];
$currentCategory = contentModuleSubstring(trim((string)($_GET['category'] ?? '')), 0, 100);
$currentQuery = contentModuleSubstring(trim((string)($_GET['q'] ?? '')), 0, 100);
$currentPage = max(1, (int)($documentResults['page'] ?? 1));
$totalPages = max(1, (int)($documentResults['total_pages'] ?? 1));
$paginationStart = max(1, $currentPage - 2);
$paginationEnd = min($totalPages, $currentPage + 2);
$docsUrl = function($page) use ($currentCategory, $currentQuery) {
    $params = [];
    if ($currentQuery !== '') $params['q'] = $currentQuery;
    if ($currentCategory !== '') $params['category'] = $currentCategory;
    if ($page > 1) $params['page'] = $page;
    return '/docs' . ($params ? '?' . http_build_query($params) : '');
};
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main id="main-content" class="site-main docs-page">
    <section class="page-hero docs-hero">
        <div class="site-container page-hero-grid">
            <div data-reveal>
                <span class="site-eyebrow"><i class="bi bi-book"></i> Knowledge base</span>
                <h1>文档中心</h1>
                <p>从入门到深入，快速找到说明、指南和可以带走的资料。</p>
            </div>
            <form class="hero-search docs-search" method="get" action="/docs" role="search" data-reveal>
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="docs-query">搜索文档</label>
                <input id="docs-query" name="q" value="<?= e($currentQuery) ?>" placeholder="搜索标题、摘要或分类" maxlength="100">
                <?php if ($currentCategory !== ''): ?><input type="hidden" name="category" value="<?= e($currentCategory) ?>"><?php endif; ?>
                <button type="submit">查找</button>
            </form>
        </div>
    </section>

    <section class="site-section docs-archive" aria-labelledby="docs-list-title">
        <div class="site-container docs-layout">
            <aside class="docs-sidebar" aria-label="文档分类">
                <div class="docs-sidebar-card">
                    <span class="small-label">浏览分类</span>
                    <nav class="docs-category-list">
                        <a class="<?= $currentCategory === '' ? 'is-active' : '' ?>" href="<?= e('/docs' . ($currentQuery !== '' ? '?q=' . rawurlencode($currentQuery) : '')) ?>">
                            <span><i class="bi bi-collection"></i>全部文档</span><strong><?= (int)($documentResults['total'] ?? 0) ?></strong>
                        </a>
                        <?php foreach ($documentCategories as $category):
                            $params = ['category' => $category['name']];
                            if ($currentQuery !== '') $params['q'] = $currentQuery;
                        ?>
                        <a class="<?= $currentCategory === (string)$category['name'] ? 'is-active' : '' ?>" href="/docs?<?= e(http_build_query($params)) ?>">
                            <span><i class="bi bi-folder2"></i><?= e($category['name']) ?></span><strong><?= (int)$category['count'] ?></strong>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <a class="docs-help-card" href="/guestbook">
                    <span><i class="bi bi-chat-dots"></i></span>
                    <strong>没有找到答案？</strong>
                    <small>前往留言板告诉我们。</small>
                </a>
            </aside>

            <div class="docs-results">
                <header class="archive-heading">
                    <div>
                        <span class="site-eyebrow"><i class="bi bi-journals"></i> Library</span>
                        <h2 id="docs-list-title"><?= $currentQuery !== '' ? '“' . e($currentQuery) . '”的结果' : ($currentCategory !== '' ? e($currentCategory) : '全部文档') ?></h2>
                    </div>
                    <span>共 <?= (int)($documentResults['total'] ?? 0) ?> 篇</span>
                </header>

                <?php if (!empty($documentResults['items'])): ?>
                <div class="docs-grid">
                    <?php foreach ($documentResults['items'] as $item): ?>
                    <article class="doc-card" data-reveal>
                        <div class="doc-card-top">
                            <span class="doc-card-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <?php if (!empty($item['is_featured'])): ?><span class="site-badge is-featured"><i class="bi bi-star-fill"></i>推荐</span><?php endif; ?>
                        </div>
                        <div>
                            <?php if (!empty($item['category'])): ?><span class="doc-category"><?= e($item['category']) ?></span><?php endif; ?>
                            <h3><a href="/docs/<?= rawurlencode($item['slug']) ?>"><?= e($item['title']) ?></a></h3>
                            <p><?= e($item['summary'] ?: '打开文档查看完整内容与相关资料。') ?></p>
                        </div>
                        <footer>
                            <span><i class="bi bi-clock"></i><?= !empty($item['updated_at']) ? e(date('Y-m-d', strtotime($item['updated_at']))) : '持续更新' ?></span>
                            <span><i class="bi bi-download"></i><?= (int)$item['download_count'] ?></span>
                            <a href="/docs/<?= rawurlencode($item['slug']) ?>" aria-label="阅读 <?= e($item['title']) ?>"><i class="bi bi-arrow-up-right"></i></a>
                        </footer>
                    </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav class="site-pagination" aria-label="文档分页">
                    <a class="pagination-direction <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e($docsUrl(max(1, $currentPage - 1))) ?>"><i class="bi bi-arrow-left"></i>上一页</a>
                    <div>
                        <?php for ($page = $paginationStart; $page <= $paginationEnd; $page++): ?>
                        <a class="<?= $page === $currentPage ? 'is-active' : '' ?>" href="<?= e($docsUrl($page)) ?>" <?= $page === $currentPage ? 'aria-current="page"' : '' ?>><?= $page ?></a>
                        <?php endfor; ?>
                    </div>
                    <a class="pagination-direction <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($docsUrl(min($totalPages, $currentPage + 1))) ?>">下一页<i class="bi bi-arrow-right"></i></a>
                </nav>
                <?php endif; ?>
                <?php else: ?>
                <div class="site-empty-state">
                    <span><i class="bi bi-journal-x"></i></span>
                    <h2>暂时没有找到文档</h2>
                    <p>换一个关键词，或回到全部分类继续看看。</p>
                    <a class="site-button site-button-quiet" href="/docs">查看全部文档</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php include $themePath . '/partials/footer.php'; ?>
