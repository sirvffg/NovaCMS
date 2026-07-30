<?php
$siteName = trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS';
$currentPageTitle = trim((string)($pageTitle ?? ''));
$documentTitle = $currentPageTitle !== '' && $currentPageTitle !== $siteName
    ? $currentPageTitle . ' · ' . $siteName
    : $siteName;
$siteDescription = trim(strip_tags((string)($pageDescription ?? '')));
if ($siteDescription === '') {
    $siteDescription = trim(strip_tags((string)($config['robot_description'] ?? '')));
}
if ($siteDescription === '') {
    $siteDescription = trim(strip_tags((string)($config['description'] ?? '')));
}
if ($siteDescription === '') {
    $siteDescription = $siteName . '，记录知识、灵感与持续成长。';
}
$faviconUrl = trim((string)($config['favicon'] ?? ''));
$faviconIsSafe = $faviconUrl !== '' && !preg_match('/[\x00-\x1F\x7F\\\\]/', $faviconUrl) && (
    (str_starts_with($faviconUrl, '/') && !str_starts_with($faviconUrl, '//')) ||
    (filter_var($faviconUrl, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($faviconUrl, PHP_URL_SCHEME)), ['http', 'https'], true))
);
$styleVersion = isset($themePath) ? (int)@filemtime($themePath . '/assets/css/style.css') : 0;
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light" data-theme="light" data-site-name="<?= e($siteName) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta id="nova-theme-color" name="theme-color" content="#faf9f6">
    <script>
        (function (root) {
            var theme = 'light';
            try {
                var stored = window.localStorage.getItem('nova-theme') || window.localStorage.getItem('theme');
                if (stored === 'light' || stored === 'dark') {
                    theme = stored;
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                }
            } catch (error) {
                theme = 'light';
            }
            root.setAttribute('data-bs-theme', theme);
            root.setAttribute('data-theme', theme);
            root.style.colorScheme = theme;
            document.getElementById('nova-theme-color').setAttribute('content', theme === 'dark' ? '#0b1220' : '#faf9f6');
        }(document.documentElement));
    </script>
    <title><?= e($documentTitle) ?></title>
    <meta name="description" content="<?= e($siteDescription) ?>">
    <meta name="author" content="<?= e((string)($config['website_author'] ?? $siteName)) ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($documentTitle) ?>">
    <meta property="og:description" content="<?= e($siteDescription) ?>">
    <?php if (!empty($config['bing_verification'])): ?>
        <meta name="msvalidate.01" content="<?= e((string)$config['bing_verification']) ?>">
    <?php endif; ?>
    <?php if ($faviconIsSafe): ?>
        <link rel="icon" href="<?= e($faviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/style.css<?= $styleVersion > 0 ? '?v=' . $styleVersion : '' ?>">
    <?php if (!empty($extraHead)): ?>
        <?= $extraHead ?>
    <?php endif; ?>
</head>
<body class="nova-site">
    <a class="nova-skip-link" href="#main-content">跳到正文</a>
