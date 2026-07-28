<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <script>
        (function() {
            const storedTheme = localStorage.getItem('theme');
            if (storedTheme) {
                document.documentElement.setAttribute('data-bs-theme', storedTheme);
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? $config['website_name']) ?> - <?= e($config['website_name']) ?></title>
    <meta name="description" content="<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>">
    <meta name="keywords" content="<?= e($config['website_name']) ?>,博客,技术分享,个人网站,编程,生活">
    <meta name="author" content="<?= e($config['website_name']) ?>">
    <meta name="robots" content="index, follow">
    <?php if (!empty($config['bing_verification'])): ?>
    <meta name="msvalidate.01" content="<?= e($config['bing_verification']) ?>" />
    <?php endif; ?>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <?php if (!empty($config['favicon'])): ?>
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <link rel="icon" href="<?= e($config['favicon']) ?>" type="image/x-icon">
    <link rel="apple-touch-icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?= getResourceUrl('/assets/css/all.min.css', 'https://cdn.staticfile.net/font-awesome/6.5.1/css/all.min.css') ?>">
    <link href="/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/inline-extra.css?v=20260630" rel="stylesheet">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body>
