<?php
$pageTitle = '站点公告';
$pageKey = 'announcement';
$pageDescription = '查看站点发布的最新公告与重要更新。';
$announcements = [];
try {
    $announcementStatement = $db->query(
        'SELECT id, title, content, created_at FROM license_announcements WHERE is_active = 1 ORDER BY created_at DESC, id DESC LIMIT 50'
    );
    $announcements = $announcementStatement ? $announcementStatement->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $exception) {
    error_log('Unable to load announcements: ' . $exception->getMessage());
}
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main lumen-announcement-page">
    <section class="page-hero lumen-announcement-hero">
        <div class="site-container">
            <span class="site-eyebrow"><i class="bi bi-megaphone" aria-hidden="true"></i> Notice board</span>
            <h1>站点公告</h1>
            <p>重要变化、服务状态与最近更新都会记录在这里。</p>
        </div>
    </section>
    <section class="site-section">
        <div class="site-container lumen-announcement-list">
            <?php if (!$announcements): ?>
                <div class="empty-state">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <h2>暂时没有公告</h2>
                    <p>一切运行平稳，有新消息时再回来看看。</p>
                    <a class="site-button site-button-primary" href="/">返回首页</a>
                </div>
            <?php else: ?>
                <?php foreach ($announcements as $announcement): ?>
                    <article class="lumen-announcement-card">
                        <header>
                            <span><i class="bi bi-broadcast" aria-hidden="true"></i>公告</span>
                            <time datetime="<?= e((string)($announcement['created_at'] ?? '')) ?>">
                                <?= e(!empty($announcement['created_at']) ? date('Y.m.d', strtotime((string)$announcement['created_at'])) : '') ?>
                            </time>
                        </header>
                        <h2><?= e((string)($announcement['title'] ?? '未命名公告')) ?></h2>
                        <div class="article-content"><?= nl2br(e((string)($announcement['content'] ?? ''))) ?></div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
