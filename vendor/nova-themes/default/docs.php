<?php
$pageTitle = '文档中心';
$documentResults = is_array($documentResults ?? null) ? $documentResults : [
    'items' => [],
    'page' => 1,
    'total' => 0,
    'total_pages' => 1,
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
    if ($currentQuery !== '') {
        $params['q'] = $currentQuery;
    }
    if ($currentCategory !== '') {
        $params['category'] = $currentCategory;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    return '/docs' . ($params ? '?' . http_build_query($params) : '');
};
$extraHead = <<<'HTML'
<style>
.docs-shell{padding-top:112px;padding-bottom:72px;min-height:72vh}
.docs-hero{position:relative;overflow:hidden;padding:clamp(1.6rem,5vw,3.4rem);border:1px solid var(--bs-border-color);border-radius:28px;background:linear-gradient(135deg,rgba(var(--bs-primary-rgb),.13),rgba(99,102,241,.04))}
.docs-hero:after{content:"";position:absolute;width:280px;height:280px;right:-90px;top:-140px;border-radius:50%;background:rgba(var(--bs-primary-rgb),.12);filter:blur(2px)}
.docs-hero-content{position:relative;z-index:1;max-width:760px}
.docs-eyebrow{display:inline-flex;align-items:center;gap:.45rem;color:var(--bs-primary);font-size:.82rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.docs-title{font-size:clamp(2rem,5vw,3.5rem);margin:.75rem 0 .8rem}
.docs-search{position:relative;z-index:2;margin-top:1.6rem;padding:.75rem;border:1px solid var(--bs-border-color);border-radius:18px;background:var(--bs-body-bg);box-shadow:0 14px 38px rgba(15,23,42,.08)}
.docs-search .form-control,.docs-search .form-select{min-height:46px;border:0;background:transparent}
.docs-search .form-control:focus,.docs-search .form-select:focus{box-shadow:none}
.docs-filter-row{display:flex;flex-wrap:wrap;gap:.6rem;margin:1.5rem 0}
.docs-filter{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem .85rem;border:1px solid var(--bs-border-color);border-radius:999px;color:var(--bs-body-color);text-decoration:none;background:var(--bs-body-bg)}
.docs-filter:hover,.docs-filter.active{border-color:var(--bs-primary);background:rgba(var(--bs-primary-rgb),.1);color:var(--bs-primary)}
.doc-card{height:100%;padding:1.35rem;border:1px solid var(--bs-border-color);border-radius:20px;background:var(--bs-body-bg);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.doc-card:hover{transform:translateY(-4px);border-color:rgba(var(--bs-primary-rgb),.45);box-shadow:0 18px 38px rgba(15,23,42,.09)}
.doc-card-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:14px;color:var(--bs-primary);background:rgba(var(--bs-primary-rgb),.1);font-size:1.25rem}
.doc-card-title a{color:inherit;text-decoration:none}
.doc-card-summary{color:var(--bs-secondary-color);line-height:1.7}
.doc-card-meta{display:flex;flex-wrap:wrap;gap:.8rem;color:var(--bs-secondary-color);font-size:.82rem}
.docs-empty{padding:4rem 1rem;text-align:center;border:1px dashed var(--bs-border-color);border-radius:22px}
</style>
HTML;
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main class="docs-shell">
    <div class="container">
        <section class="docs-hero">
            <div class="docs-hero-content">
                <span class="docs-eyebrow"><i class="bi bi-journal-richtext"></i>Knowledge base</span>
                <h1 class="docs-title">文档中心</h1>
                <p class="lead text-body-secondary mb-0">查找使用指南、帮助文档和可下载资料。</p>
            </div>

            <form class="docs-search" method="get" action="/docs" role="search">
                <div class="row g-2 align-items-center">
                    <div class="col-lg">
                        <label class="visually-hidden" for="docs-query">搜索文档</label>
                        <div class="input-group">
                            <span class="input-group-text border-0 bg-transparent"><i class="bi bi-search"></i></span>
                            <input class="form-control" id="docs-query" name="q" value="<?= e($currentQuery) ?>" placeholder="搜索标题、摘要或分类">
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <label class="visually-hidden" for="docs-category">文档分类</label>
                        <select class="form-select" id="docs-category" name="category">
                            <option value="">全部分类</option>
                            <?php foreach ($documentCategories as $category): ?>
                                <option value="<?= e($category['name']) ?>" <?= $currentCategory === $category['name'] ? 'selected' : '' ?>>
                                    <?= e($category['name']) ?>（<?= (int)$category['count'] ?>）
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-auto">
                        <button class="btn btn-primary px-4 w-100" type="submit">查找文档</button>
                    </div>
                </div>
            </form>
        </section>

        <?php if ($documentCategories): ?>
            <nav class="docs-filter-row" aria-label="文档分类">
                <a class="docs-filter <?= $currentCategory === '' ? 'active' : '' ?>" href="<?= e('/docs' . ($currentQuery !== '' ? '?q=' . rawurlencode($currentQuery) : '')) ?>">全部</a>
                <?php foreach ($documentCategories as $category): ?>
                    <?php
                    $categoryParams = ['category' => $category['name']];
                    if ($currentQuery !== '') {
                        $categoryParams['q'] = $currentQuery;
                    }
                    ?>
                    <a class="docs-filter <?= $currentCategory === $category['name'] ? 'active' : '' ?>"
                       href="/docs?<?= e(http_build_query($categoryParams)) ?>">
                        <?= e($category['name']) ?>
                        <span class="badge text-bg-light"><?= (int)$category['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-3">
            <h2 class="h4 mb-0"><?= $currentQuery !== '' ? '搜索结果' : ($currentCategory !== '' ? e($currentCategory) : '全部文档') ?></h2>
            <span class="text-body-secondary small">共 <?= (int)($documentResults['total'] ?? 0) ?> 篇</span>
        </div>

        <?php if (!empty($documentResults['items'])): ?>
            <div class="row g-4">
                <?php foreach ($documentResults['items'] as $item): ?>
                    <div class="col-md-6 col-xl-4">
                        <article class="doc-card d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <span class="doc-card-icon"><i class="bi bi-file-earmark-text"></i></span>
                                <?php if (!empty($item['is_featured'])): ?>
                                    <span class="badge rounded-pill text-bg-primary"><i class="bi bi-star-fill me-1"></i>推荐</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['category'])): ?>
                                <div class="small text-primary fw-semibold mb-2"><?= e($item['category']) ?></div>
                            <?php endif; ?>
                            <h3 class="doc-card-title h5">
                                <a href="/docs/<?= rawurlencode($item['slug']) ?>"><?= e($item['title']) ?></a>
                            </h3>
                            <p class="doc-card-summary flex-grow-1"><?= e($item['summary'] ?: '打开文档查看完整内容。') ?></p>
                            <div class="doc-card-meta mt-3">
                                <?php if (!empty($item['updated_at'])): ?>
                                    <span><i class="bi bi-clock me-1"></i><?= e(date('Y-m-d', strtotime($item['updated_at']))) ?></span>
                                <?php endif; ?>
                                <span><i class="bi bi-download me-1"></i><?= (int)$item['download_count'] ?></span>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="mt-5" aria-label="文档分页">
                    <ul class="pagination justify-content-center flex-wrap">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($docsUrl(max(1, $currentPage - 1))) ?>">上一页</a>
                        </li>
                        <?php for ($page = $paginationStart; $page <= $paginationEnd; $page++): ?>
                            <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($docsUrl($page)) ?>"><?= $page ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($docsUrl(min($totalPages, $currentPage + 1))) ?>">下一页</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="docs-empty">
                <i class="bi bi-journal-x display-5 text-body-secondary"></i>
                <h2 class="h5 mt-3">暂时没有找到文档</h2>
                <p class="text-body-secondary mb-3">可以尝试更换关键词或查看全部分类。</p>
                <a class="btn btn-outline-primary" href="/docs">查看全部文档</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include $themePath . '/partials/footer.php'; ?>
