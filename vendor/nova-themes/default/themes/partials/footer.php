<?php
$footerSiteName = $siteName ?? (trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS');
$footerDescription = trim(strip_tags((string)($config['description'] ?? '')));
if ($footerDescription === '') {
    $footerDescription = '记录值得反复阅读的内容，也分享仍在生长的想法。';
}
$contactEmail = filter_var(trim((string)($config['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
$githubUrl = trim((string)($config['social_github'] ?? ''));
if ($githubUrl !== '' && !preg_match('#^https?://#i', $githubUrl)) {
    $githubName = ltrim($githubUrl, '@/');
    $githubUrl = preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $githubName)
        ? 'https://github.com/' . rawurlencode($githubName)
        : '';
}
$githubScheme = strtolower((string)parse_url($githubUrl, PHP_URL_SCHEME));
$githubHost = strtolower((string)parse_url($githubUrl, PHP_URL_HOST));
if ($githubUrl === '' || !filter_var($githubUrl, FILTER_VALIDATE_URL) || !in_array($githubScheme, ['http', 'https'], true) || !in_array($githubHost, ['github.com', 'www.github.com'], true)) {
    $githubUrl = '';
}
$sanitizeFooterHtml = static function (string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedTags = '<a><span><strong><b><em><i><small><br>';
    $filtered = strip_tags($html, $allowedTags);
    if (!class_exists('DOMDocument')) {
        return e(trim(strip_tags($filtered)));
    }

    $previousLibxmlState = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="nova-footer-extra-root">' . $filtered . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);
    if (!$loaded) {
        return e(trim(strip_tags($filtered)));
    }

    $root = $document->getElementById('nova-footer-extra-root') ?: $document->getElementsByTagName('div')->item(0);
    if (!$root) {
        return e(trim(strip_tags($filtered)));
    }

    $elements = [];
    foreach ($root->getElementsByTagName('*') as $element) {
        $elements[] = $element;
    }
    foreach ($elements as $element) {
        $tag = strtolower($element->tagName);
        $allowedAttributes = $tag === 'a' ? ['href', 'title', 'target', 'rel', 'class'] : ['class'];
        for ($index = $element->attributes->length - 1; $index >= 0; $index--) {
            $attribute = $element->attributes->item($index);
            if ($attribute && !in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        if ($element->hasAttribute('class')) {
            $className = trim($element->getAttribute('class'));
            if ($className === '' || !preg_match('/^[A-Za-z0-9 _-]{1,200}$/', $className)) {
                $element->removeAttribute('class');
            }
        }

        if ($tag !== 'a') {
            continue;
        }

        $href = trim($element->getAttribute('href'));
        $isRelative = preg_match('#^(?:/[^/]|\?|#)#', $href) === 1;
        $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
        $isWebUrl = filter_var($href, FILTER_VALIDATE_URL)
            && in_array($scheme, ['http', 'https'], true)
            && parse_url($href, PHP_URL_USER) === null
            && parse_url($href, PHP_URL_PASS) === null;
        $isEmail = $scheme === 'mailto' && filter_var(substr($href, 7), FILTER_VALIDATE_EMAIL);
        if (!$isRelative && !$isWebUrl && !$isEmail) {
            $element->removeAttribute('href');
        }

        $target = $element->getAttribute('target');
        if (!in_array($target, ['', '_self', '_blank'], true)) {
            $element->removeAttribute('target');
        }
        if ($element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        } elseif ($element->hasAttribute('rel')) {
            $element->removeAttribute('rel');
        }
    }

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $document->saveHTML($child);
    }
    return trim($output);
};
$footerExtraHtml = $sanitizeFooterHtml((string)($config['footer_extra'] ?? ''));
$themeScriptVersion = isset($themePath) ? (int)@filemtime($themePath . '/assets/js/theme.js') : 0;
?>
    <footer class="site-footer">
        <div class="container">
            <div class="site-footer-grid">
                <section class="site-footer-intro" aria-labelledby="footer-site-title">
                    <a class="nova-brand nova-brand-footer" href="/">
                        <span class="nova-brand-mark" aria-hidden="true"><i class="bi bi-stars"></i></span>
                        <span class="nova-brand-copy"><strong id="footer-site-title"><?= e($footerSiteName) ?></strong><small>Ideas in progress</small></span>
                    </a>
                    <p><?= e($footerDescription) ?></p>
                    <div class="site-footer-contact">
                        <?php if ($contactEmail !== ''): ?>
                            <a class="nova-footer-icon-link" href="mailto:<?= e($contactEmail) ?>" aria-label="发送邮件"><i class="bi bi-envelope" aria-hidden="true"></i></a>
                        <?php endif; ?>
                        <?php if ($githubUrl !== ''): ?>
                            <a class="nova-footer-icon-link" href="<?= e($githubUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="访问 GitHub"><i class="bi bi-github" aria-hidden="true"></i></a>
                        <?php endif; ?>
                        <a class="nova-footer-icon-link" href="/license/rss.php" aria-label="订阅 RSS"><i class="bi bi-rss" aria-hidden="true"></i></a>
                    </div>
                </section>

                <nav class="site-footer-column" aria-label="探索">
                    <h2>探索</h2>
                    <a href="/blog">博客文章</a>
                    <a href="/instant">片刻动态</a>
                    <a href="/gallery">影像相册</a>
                </nav>

                <nav class="site-footer-column" aria-label="交流">
                    <h2>交流</h2>
                    <a href="/guestbook">留言板</a>
                    <a href="/friend-links">友情链接</a>
                    <a href="/profile">个人中心</a>
                    <?php if ($contactEmail !== ''): ?>
                        <a href="mailto:<?= e($contactEmail) ?>">联系站点</a>
                    <?php endif; ?>
                </nav>

                <nav class="site-footer-column" aria-label="站点信息">
                    <h2>站点</h2>
                    <a href="/license/terms.php">服务与隐私</a>
                    <a href="/license/rss.php">RSS 订阅</a>
                    <a href="/license/sitemap.php">站点地图</a>
                    <button type="button" data-theme-toggle><span data-theme-label>切换深色主题</span></button>
                </nav>
            </div>

            <?php if ($footerExtraHtml !== ''): ?>
                <div class="site-footer-extra"><?= $footerExtraHtml ?></div>
            <?php endif; ?>

            <div class="site-footer-bottom">
                <p>&copy; <?= date('Y') ?> <?= e($footerSiteName) ?>. 保留所有权利。</p>
                <div class="site-footer-records">
                    <?php if (!empty($config['icp_record'])): ?>
                        <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= e((string)$config['icp_record']) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($config['public_security_record'])): ?>
                        <span><?= e((string)$config['public_security_record']) ?></span>
                    <?php endif; ?>
                    <span>由 NovaCMS 驱动</span>
                </div>
            </div>
        </div>
    </footer>

    <dialog id="nova-search-dialog" class="nova-search-dialog" aria-labelledby="nova-search-title">
        <form class="nova-search-panel" action="/blog" method="get" role="search">
            <div class="nova-search-heading">
                <div>
                    <span class="nova-eyebrow">Search the site</span>
                    <h2 id="nova-search-title">查找站内内容</h2>
                </div>
                <button class="nova-icon-button" type="button" data-search-close aria-label="关闭搜索"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <label class="nova-search-field" for="nova-site-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="visually-hidden">搜索关键词</span>
                <input id="nova-site-search" name="q" type="search" autocomplete="off" placeholder="输入文章标题、主题或关键词" maxlength="100" required>
                <kbd>Enter</kbd>
            </label>
            <div class="nova-search-shortcuts">
                <span>快速前往</span>
                <a href="/blog">全部文章</a>
                <a href="/gallery">相册</a>
            </div>
        </form>
    </dialog>

    <div id="nova-toast-region" class="nova-toast-region" aria-live="polite" aria-atomic="false"></div>
    <button id="nova-back-to-top" class="nova-back-to-top" type="button" aria-label="回到页面顶部" title="回到顶部">
        <i class="bi bi-arrow-up" aria-hidden="true"></i>
    </button>

    <script src="<?= e(NOVA_THEME_URL) ?>/assets/js/bootstrap.bundle.min.js"></script>
    <script src="<?= e(NOVA_THEME_URL) ?>/assets/js/theme.js<?= $themeScriptVersion > 0 ? '?v=' . $themeScriptVersion : '' ?>"></script>
    <?php if (!empty($extraFooter)): ?>
        <?= $extraFooter ?>
    <?php endif; ?>
</body>
</html>
