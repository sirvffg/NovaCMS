<?php
/**
 * 站点地图生成
 * 由 page_routes 路由调用，读取 config.json 配置生成 sitemap XML
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

header('Content-Type: application/xml; charset=utf-8');

// index.php 已加载 config/database.php，getDB() 可直接使用

// 读取插件配置
function sitemap_get_config() {
    $configFile = dirname(__DIR__) . '/config.json';
    $defaults = [
        'homepage_changefreq' => 'daily',
        'homepage_priority' => 1.0,
        'post_priority' => 0.8,
        'include_blog' => true,
        'include_gallery' => true,
        'include_guestbook' => true,
        'include_shuoshuo' => true,
        'include_friend_links' => true,
        'include_images' => true,
        'custom_urls' => '',
    ];
    if (!is_file($configFile)) return $defaults;
    $cfg = json_decode(file_get_contents($configFile), true);
    if (!is_array($cfg) || empty($cfg['tabs'])) return $defaults;
    foreach ($cfg['tabs'] as $tab) {
        foreach ($tab['fields'] ?? [] as $field) {
            $name = $field['name'] ?? '';
            if ($name && array_key_exists($name, $defaults)) {
                $defaults[$name] = $field['value'] ?? $defaults[$name];
            }
        }
    }
    return $defaults;
}

$cfg = sitemap_get_config();

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$today = date('Y-m-d');

// 查询数据
$posts = [];
if ($cfg['include_blog']) {
    $posts = $db->query("SELECT id, title, cover_image, created_at FROM blog_posts WHERE is_published = 1 ORDER BY created_at DESC")->fetchAll();
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">

    <!-- 首页 -->
    <url>
        <loc><?= $baseUrl ?></loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq><?= $cfg['homepage_changefreq'] ?></changefreq>
        <priority><?= $cfg['homepage_priority'] ?></priority>
    </url>

    <!-- 博客列表页 -->
    <url>
        <loc><?= $baseUrl ?>/blog</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>

    <?php if ($cfg['include_blog']): ?>
    <!-- 博客文章 -->
    <?php foreach ($posts as $post): ?>
    <url>
        <loc><?= $baseUrl ?>/blog.php?id=<?= $post['id'] ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($post['created_at'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority><?= $cfg['post_priority'] ?></priority>
        <?php if ($cfg['include_images'] && !empty($post['cover_image'])): ?>
        <image:image>
            <image:loc><?= $baseUrl . '/' . ltrim($post['cover_image'], '/') ?></image:loc>
            <image:title><?= htmlspecialchars($post['title']) ?></image:title>
        </image:image>
        <?php endif; ?>
    </url>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($cfg['include_friend_links']): ?>
    <!-- 友情链接 -->
    <url>
        <loc><?= $baseUrl ?>/friend-links</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.6</priority>
    </url>
    <?php endif; ?>

    <?php if ($cfg['include_shuoshuo']): ?>
    <!-- 说说 -->
    <url>
        <loc><?= $baseUrl ?>/shuoshuo</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
    <?php endif; ?>

    <?php if ($cfg['include_gallery']): ?>
    <!-- 相册 -->
    <url>
        <loc><?= $baseUrl ?>/gallery</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.6</priority>
    </url>
    <?php endif; ?>

    <?php if ($cfg['include_guestbook']): ?>
    <!-- 留言板 -->
    <url>
        <loc><?= $baseUrl ?>/guestbook</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
    <?php endif; ?>

    <!-- RSS -->
    <url>
        <loc><?= $baseUrl ?>/rss.xml</loc>
        <changefreq>always</changefreq>
        <priority>0.6</priority>
    </url>

    <?php
    // 自定义 URL
    if (!empty($cfg['custom_urls'])) {
        $lines = array_filter(array_map('trim', explode("\n", $cfg['custom_urls'])));
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            $path = trim($parts[0] ?? '');
            if ($path === '') continue;
            $priority = trim($parts[1] ?? '0.5');
            $changefreq = trim($parts[2] ?? 'monthly');
            $url = (strpos($path, 'http') === 0) ? $path : ($baseUrl . '/' . ltrim($path, '/'));
    ?>
    <!-- 自定义: <?= htmlspecialchars($path) ?> -->
    <url>
        <loc><?= htmlspecialchars($url) ?></loc>
        <changefreq><?= htmlspecialchars($changefreq) ?></changefreq>
        <priority><?= htmlspecialchars($priority) ?></priority>
    </url>
    <?php
        }
    }
    ?>

</urlset>
