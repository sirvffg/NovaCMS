<?php
/**
 * RSS 订阅源生成
 * 由 page_routes 路由调用，读取 config.json 配置生成 RSS XML
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

header('Content-Type: application/rss+xml; charset=utf-8');

// index.php 已加载 config/database.php 和 config/functions.php，getDB() 等全局函数可直接使用

// 读取插件配置
function rss_get_config() {
    $configFile = dirname(__DIR__) . '/config.json';
    $defaults = [
        'feed_title' => '',
        'feed_description' => '最新文章订阅',
        'max_items' => 20,
        'enable_full_text' => true,
        'enable_image' => true,
        'custom_channel_tags' => '',
        'custom_item_tags' => '',
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

$cfg = rss_get_config();

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

$maxItems = max(1, (int)($cfg['max_items'] ?? 20));
$posts = $db->query("SELECT * FROM blog_posts WHERE is_published=1 ORDER BY created_at DESC LIMIT {$maxItems}")->fetchAll();

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$feedTitle = !empty($cfg['feed_title']) ? $cfg['feed_title'] : ($config['website_name'] ?? '我的博客');
$feedDesc = $cfg['feed_description'] ?? '最新文章订阅';
$currentDate = date(DATE_RSS);

// 解析自定义标签
$customChannelTags = '';
if (!empty($cfg['custom_channel_tags'])) {
    foreach (explode("\n", $cfg['custom_channel_tags']) as $line) {
        $line = trim($line);
        if ($line !== '') $customChannelTags .= "    " . $line . "\n";
    }
}
$customItemTags = '';
if (!empty($cfg['custom_item_tags'])) {
    $lines = array_filter(array_map('trim', explode("\n", $cfg['custom_item_tags'])));
    $customItemTags = implode("\n            ", $lines);
}

// 清理内容
function rss_clean_content($content, $baseUrl, $fullText) {
    // Parsedown 解析（若系统已加载则直接使用）
    if (class_exists('Parsedown', false)) {
        $parsedown = new Parsedown();
        $content = $parsedown->text($content);
    } else {
        $parsedownPath = dirname(__DIR__, 4) . '/vendor/public/Parsedown.php';
        if (is_file($parsedownPath)) {
            require_once $parsedownPath;
            $parsedown = new Parsedown();
            $content = $parsedown->text($content);
        }
    }

    // 相对图片路径转绝对
    $content = preg_replace('/<img\s+([^>]*?)src\s*=\s*"\/([^"]+)"/i', '<img $1src="' . $baseUrl . '/$2"', $content);

    // 颜色标签
    $content = preg_replace('/<color:(\w+)>/i', '<span style="color:$1">', $content);
    $content = preg_replace('/<\/color:\w+>/i', '</span>', $content);

    if (!$fullText) {
        // 摘要模式：取前 300 字符
        $text = strip_tags($content);
        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300, 'UTF-8') . '...';
        }
        return $text;
    }

    return $content;
}

function rss_replace_privacy($content, $link) {
    $content = preg_replace('/\[Privacy\].*?\[\/Privacy\]/s',
        '<div style="background:#f0f0f0;padding:15px;border-radius:8px;margin:15px 0;border-left:4px solid #667eea;"><strong>此内容需要访问网站查看</strong><br><br><a href="' . $link . '" style="color:#667eea;text-decoration:none;font-weight:500;">点击前往查看 →</a></div>',
        $content);
    $content = preg_replace('/\[Paid\].*?\[\/Paid\]/s',
        '<div style="background:#fff3cd;padding:15px;border-radius:8px;margin:15px 0;border-left:4px solid #ffc107;"><strong>此内容需要访问网站付费查看</strong><br><br><a href="' . $link . '" style="color:#856404;text-decoration:none;font-weight:500;">点击前往查看 →</a></div>',
        $content);
    return $content;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= htmlspecialchars($feedTitle) ?></title>
    <link><?= $baseUrl ?></link>
    <description><?= htmlspecialchars($feedDesc) ?></description>
    <language>zh-cn</language>
    <lastBuildDate><?= $currentDate ?></lastBuildDate>
    <generator>NovaCMS RSS Plugin</generator>
    <atom:link href="<?= $baseUrl ?>/rss.xml" rel="self" type="application/rss+xml"></atom:link>
<?php if ($customChannelTags): ?>
<?= $customChannelTags ?>
<?php endif; ?>
    <?php foreach ($posts as $post):
        $postUrl = $baseUrl . '/blog.php?id=' . $post['id'];
        $content = $post['content'];
        $content = rss_replace_privacy($content, $postUrl);
        $content = rss_clean_content($content, $baseUrl, $cfg['enable_full_text']);
    ?>
    <item>
        <title><?= htmlspecialchars($post['title']) ?></title>
        <link><?= $postUrl ?></link>
        <guid><?= $postUrl ?></guid>
        <pubDate><?= date(DATE_RSS, strtotime($post['created_at'])) ?></pubDate>
        <description><![CDATA[<?= $content ?>]]></description>
        <?php if (!empty($post['author'])): ?>
        <author><?= htmlspecialchars($config['contact_email'] ?? 'admin@example.com') ?> (<?= htmlspecialchars($post['author']) ?>)</author>
        <?php endif; ?>
        <?php if (!empty($post['category'])): ?>
        <category><?= htmlspecialchars($post['category']) ?></category>
        <?php endif; ?>
        <?php if ($cfg['enable_image'] && !empty($post['cover_image'])): ?>
        <enclosure url="<?= $baseUrl . '/' . ltrim($post['cover_image'], '/') ?>" type="image/jpeg" length="0" />
        <?php endif; ?>
        <?php if ($customItemTags): ?>
        <?= $customItemTags ?>
        <?php endif; ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
