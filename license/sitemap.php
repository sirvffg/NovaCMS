<?php
// 生成网站地图
header('Content-Type: application/xml; charset=utf-8');

require_once '../config/database.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$posts = $db->query("SELECT id, title, cover_image, created_at FROM blog_posts WHERE is_published = 1 ORDER BY created_at DESC")->fetchAll();
$albums = $db->query("SELECT id, name, cover_image, created_at FROM photo_albums ORDER BY created_at DESC")->fetchAll();
$friendLinks = $db->query("SELECT * FROM friend_links ORDER BY sort_order ASC, id DESC")->fetchAll();

// 获取网站基础URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">
    
    <!-- 首页 -->
    <url>
        <loc><?= $baseUrl ?></loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    
    <!-- 博客列表页 -->
    <url>
        <loc><?= $baseUrl ?>/blog.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    
    <!-- 博客文章 -->
    <?php foreach ($posts as $post): ?>
    <url>
        <loc><?= $baseUrl ?>/blog.php?id=<?= $post['id'] ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($post['created_at'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
        <!-- 文章: <?= htmlspecialchars($post['title']) ?> -->
        <?php if (!empty($post['cover_image'])): ?>
        <image:image>
            <image:loc><?= $baseUrl . '/' . ltrim($post['cover_image'], '/') ?></image:loc>
            <image:title><?= htmlspecialchars($post['title']) ?></image:title>
        </image:image>
        <?php endif; ?>
    </url>
    <?php endforeach; ?>

    <!-- 友情链接列表页 -->
    <url>
        <loc><?= $baseUrl ?>/vendor/friend-links.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    
    <!-- 说说 -->
    <url>
        <loc><?= $baseUrl ?>/vendor/shuoshuo.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- 相册列表页 -->
    <url>
        <loc><?= $baseUrl ?>/vendor/gallery.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- 留言板 -->
    <url>
        <loc><?= $baseUrl ?>/vendor/guestbook.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- 协议 -->
    <url>
        <loc><?= $baseUrl ?>/license/terms.php</loc>
        <changefreq>monthly</changefreq>
        <priority>0.5</priority>
    </url>

    <!-- RSS -->
    <url>
        <loc><?= $baseUrl ?>/license/rss.php</loc>
        <changefreq>always</changefreq>
        <priority>0.6</priority>
    </url>
    
</urlset>
